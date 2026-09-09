#!/usr/bin/env python3
"""Extract Maverick property records and linked photos from the legacy WordPress backup."""
from __future__ import annotations

import argparse
import re
import shutil
import zipfile
from collections import defaultdict
from pathlib import Path


TABLES = {"wp_posts", "wp_postmeta", "wp_terms", "wp_term_taxonomy", "wp_term_relationships"}


def split_row(line: str) -> list[str] | None:
    text = line.strip().rstrip(",;")
    if not (text.startswith("(") and text.endswith(")")):
        return None
    values, buffer = [], []
    quoted = escaped = False
    translations = {"n": "\n", "r": "\r", "t": "\t", "0": "\0"}
    for char in text[1:-1]:
        if quoted:
            if escaped:
                buffer.append(translations.get(char, char))
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == "'":
                quoted = False
            else:
                buffer.append(char)
        elif char == "'":
            quoted = True
        elif char == ",":
            values.append("".join(buffer).strip())
            buffer = []
        else:
            buffer.append(char)
    values.append("".join(buffer).strip())
    return values


def read_tables(sql_path: Path) -> dict[str, list[dict[str, str]]]:
    tables: dict[str, list[dict[str, str]]] = defaultdict(list)
    current, columns = None, []
    header = re.compile(r"INSERT INTO [`]([^`]+)[`] [(]([^)]*)[)] VALUES")
    with sql_path.open(encoding="utf-8", errors="replace") as stream:
        for line in stream:
            if line.startswith("INSERT INTO "):
                match = header.match(line)
                current = match.group(1) if match and match.group(1) in TABLES else None
                columns = [item.strip().strip("`") for item in match.group(2).split(",")] if current else []
                continue
            if not current:
                continue
            values = split_row(line)
            if values and len(values) == len(columns):
                tables[current].append(dict(zip(columns, values)))
    return tables


def sql_value(value) -> str:
    if value is None or value == "":
        return "NULL"
    if isinstance(value, (int, float)):
        return str(value)
    escaped = str(value).replace("\\", "\\\\").replace("'", "''").replace("\0", "")
    return f"'{escaped}'"


def number(value: str | None):
    if not value:
        return None
    match = re.search(r"-?\d+(?:\.\d+)?", value.replace(",", ""))
    return match.group(0) if match else None


def gps(value: str) -> tuple[str, str]:
    lat = re.search(r'"latitude";s:\d+:"([^"]+)"', value)
    lng = re.search(r'"longitude";s:\d+:"([^"]+)"', value)
    return (lat.group(1) if lat else "", lng.group(1) if lng else "")


def address_parts(value: str) -> tuple[str, str, str, str]:
    clean = " ".join(value.replace("\r", " ").replace("\n", " ").split())
    parts = [part.strip() for part in clean.split(",")]
    line1 = parts[0] if parts else clean
    city = parts[1] if len(parts) > 1 else "Evansville"
    tail = " ".join(parts[2:]) if len(parts) > 2 else "IN"
    match = re.search(r"\b([A-Z]{2})\s+(\d{5}(?:-\d{4})?)\b", tail)
    return line1, city, match.group(1) if match else "IN", match.group(2) if match else ""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("sql", type=Path)
    parser.add_argument("archive", type=Path)
    parser.add_argument("project", type=Path)
    args = parser.parse_args()

    tables = read_tables(args.sql)
    posts = {int(row["ID"]): row for row in tables["wp_posts"] if row.get("post_type") == "dt_properties" and row.get("post_status") == "publish"}
    metadata: dict[int, dict[str, str]] = defaultdict(dict)
    for row in tables["wp_postmeta"]:
        try:
            post_id = int(row["post_id"])
        except (KeyError, ValueError):
            continue
        if post_id in posts:
            metadata[post_id][row["meta_key"]] = row["meta_value"]

    terms = {row["term_id"]: row["name"] for row in tables["wp_terms"]}
    taxonomies = {row["term_taxonomy_id"]: (row["term_id"], row["taxonomy"]) for row in tables["wp_term_taxonomy"]}
    assigned: dict[int, list[tuple[str, str]]] = defaultdict(list)
    for row in tables["wp_term_relationships"]:
        try:
            post_id = int(row["object_id"])
        except (KeyError, ValueError):
            continue
        if post_id in posts and row["term_taxonomy_id"] in taxonomies:
            term_id, taxonomy = taxonomies[row["term_taxonomy_id"]]
            assigned[post_id].append((taxonomy, terms.get(term_id, "")))

    upload_root = args.project / "uploads" / "properties"
    if upload_root.exists():
        shutil.rmtree(upload_root)
    upload_root.mkdir(parents=True)
    sql_lines = ["SET NAMES utf8mb4;", "START TRANSACTION;", ""]
    photo_lines, photo_count = [], 0

    with zipfile.ZipFile(args.archive) as archive:
        archive_names = set(archive.namelist())
        for new_id, legacy_id in enumerate(sorted(posts), 1):
            post, meta = posts[legacy_id], metadata[legacy_id]
            term_values = {taxonomy: name for taxonomy, name in assigned[legacy_id]}
            legacy_type = term_values.get("property_type", "Rented").lower()
            property_type = "apartment" if legacy_type == "apartment" else "airbnb" if legacy_type == "airbnb" else "house"
            status = "sold" if legacy_type == "sold" else "rented"
            slug = post["post_name"]
            lat, lng = gps(meta.get("_property_gps", ""))
            line1, city, state, postal = address_parts(meta.get("_property_address", post["post_title"]))
            legacy_price = number(meta.get("_property_price"))
            rent = legacy_price if property_type in {"house", "apartment"} and status == "rented" else None
            nightly = legacy_price if property_type == "airbnb" else None
            sale = legacy_price if status == "sold" else None

            urls = re.findall(r'https?://[^";]+', meta.get("_property_media", ""))
            originals = []
            for url in urls:
                if "-150x150." in url or "/wp-content/uploads/" not in url:
                    continue
                source = "wp-content/uploads/" + url.split("/wp-content/uploads/", 1)[1]
                if source not in originals:
                    originals.append(source)

            destination = upload_root / slug
            destination.mkdir(parents=True, exist_ok=True)
            web_photos = []
            for order, source in enumerate(originals, 1):
                if source not in archive_names:
                    raise FileNotFoundError(f"Referenced property image is missing: {source}")
                suffix = Path(source).suffix.lower() or ".jpg"
                filename = f"{order:03d}{suffix}"
                output = destination / filename
                with archive.open(source) as incoming, output.open("wb") as outgoing:
                    shutil.copyfileobj(incoming, outgoing)
                web_path = f"uploads/properties/{slug}/{filename}"
                web_photos.append(web_path)
                photo_count += 1
                photo_lines.append(f"({new_id}, {sql_value(web_path)}, {order})")

            values = [new_id, legacy_id, slug, post["post_title"], property_type, status, line1, city, state, postal,
                      number(lat), number(lng), number(meta.get("_bedrooms")), number(meta.get("_bathrooms")),
                      number(meta.get("_area")), rent, nightly, sale, post.get("post_content") or None,
                      web_photos[0] if web_photos else None, 1]
            columns = "id,legacy_id,slug,title,property_type,status,address_line1,city,state,postal_code,latitude,longitude,bedrooms,bathrooms,square_feet,rent_amount,nightly_rate,sale_price,description,featured_photo,is_visible"
            sql_lines.append(f"INSERT INTO properties ({columns}) VALUES ({', '.join(sql_value(v) for v in values)});")

    if photo_lines:
        sql_lines.extend(["", "INSERT INTO property_photos (property_id, file_path, sort_order) VALUES", ",\n".join(photo_lines) + ";"])
    sql_lines.extend(["", "COMMIT;", ""])
    output_sql = args.project / "database" / "legacy_import.sql"
    output_sql.write_text("\n".join(sql_lines), encoding="utf-8")
    print(f"Created {output_sql} with {len(posts)} properties and {photo_count} photos.")


if __name__ == "__main__":
    main()
