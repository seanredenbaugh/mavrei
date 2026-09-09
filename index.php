<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111318">
  <title>Maverick Real Estate Investments</title>
  <style>
    :root {
      --black: #111318;
      --red: #bd001c;
      --gold: #c89b3c;
      --blue: #294861;
      --white: #ffffff;
    }

    * { box-sizing: border-box; }

    html, body { min-height: 100%; }

    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 32px 20px;
      color: var(--black);
      font-family: Arial, Helvetica, sans-serif;
      background:
        radial-gradient(circle at 15% 15%, rgba(200, 155, 60, .18), transparent 30%),
        radial-gradient(circle at 85% 80%, rgba(189, 0, 28, .15), transparent 32%),
        linear-gradient(135deg, #0d1015, #1c2430);
    }

    main {
      width: min(760px, 100%);
      padding: clamp(34px, 7vw, 64px);
      text-align: center;
      background: rgba(255, 255, 255, .98);
      border: 1px solid rgba(200, 155, 60, .7);
      border-radius: 24px;
      box-shadow: 0 28px 70px rgba(0, 0, 0, .42);
    }

    .logo {
      display: block;
      width: min(560px, 100%);
      height: auto;
      margin: 0 auto 34px;
    }

    .rule {
      width: 72px;
      height: 4px;
      margin: 0 auto 28px;
      border: 0;
      border-radius: 99px;
      background: linear-gradient(90deg, var(--red), var(--gold));
    }

    h1 {
      margin: 0;
      color: var(--black);
      font-size: clamp(28px, 5vw, 48px);
      line-height: 1.12;
      letter-spacing: -.025em;
    }

    p {
      margin: 18px 0 0;
      color: var(--blue);
      font-size: clamp(16px, 2.2vw, 20px);
      line-height: 1.6;
    }

    @media (max-width: 520px) {
      body { padding: 18px; }
      main { border-radius: 18px; }
      .logo { margin-bottom: 26px; }
    }
  </style>
</head>
<body>
  <main>
    <img class="logo" src="mavreilogo.png" alt="Maverick Real Estate Investments">
    <hr class="rule">
    <h1>New website on the way!</h1>
    <p>We’re building a new online home for Maverick Real Estate Investments.</p>
  </main>
</body>
</html>
