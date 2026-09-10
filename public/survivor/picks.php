<?php
require_once __DIR__ . '/../../src/bootstrap.php';

$user = Auth::requireLogin();
$season = CURRENT_SEASON;
$gameKey = 'survivor';
$engine = new SurvivorSquad();

$leagueId = (int)($_GET['league_id'] ?? 0);
$league = LeagueRepo::find($leagueId);
$entries = EntryRepo::forUserInLeague((int)$user['id'], $leagueId, $gameKey, $season);
$entry = EntryRepo::resolveForLeague($entries, (int)($_GET['entry_id'] ?? 0));

if (!$league || $league['game_type'] !== 'survivor' || !$entries) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}
if (!$entry) {
    require __DIR__ . '/../../templates/entry_chooser.php';
}
$entryQS = count($entries) > 1 ? '&entry_id=' . (int)$entry['id'] : '';

$status = $engine->status((int)$entry['id']); // also prunes any future picks if this entry is eliminated
$week = (int)($_GET['week'] ?? ScheduleRepo::currentWeek($season));
$weekGames = ScheduleRepo::weekGames($season, $week);
$usedTeamIds = $engine->usedTeamIds((int)$entry['id']);
$error = null;

// The pick already made for this week (if any) doesn't count against "used" for re-picking the same week.
$existingThisWeek = null;
foreach ($engine->picks((int)$entry['id']) as $p) {
    if ((int)$p['week'] === $week) {
        $existingThisWeek = $p;
    }
}
$usedTeamIds = array_diff($usedTeamIds, $existingThisWeek ? [(int)$existingThisWeek['team_id']] : []);

// Abbreviation (and game row) of the currently-saved pick for this week, if
// any — used to phrase the "change your pick from X to Y?" / "Remove your
// pick?" confirmations on the client, and to re-check server-side whether
// that specific game is still unlocked before allowing a removal.
$existingAbbr = null;
$existingGame = null;
if ($existingThisWeek) {
    foreach ($weekGames as $g) {
        if ((int)$g['home_team_id'] === (int)$existingThisWeek['team_id']) {
            $existingAbbr = $g['home_abbr'];
            $existingGame = $g;
            break;
        }
        if ((int)$g['away_team_id'] === (int)$existingThisWeek['team_id']) {
            $existingAbbr = $g['away_abbr'];
            $existingGame = $g;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();

    if (!$status['alive']) {
        $error = 'You were eliminated and can no longer make picks.';
    } elseif (($_POST['action'] ?? 'pick') === 'remove') {
        // Clicking your already-picked team again offers to remove the pick
        // outright, rather than requiring you to pick a different team just
        // to get rid of one you no longer want (e.g. planning ahead and
        // changed your mind). Only allowed while that pick's own game
        // hasn't started yet — the radio is already disabled client-side
        // once it's locked, but never trust that alone.
        if (!$existingThisWeek || !$existingGame) {
            $error = 'No pick to remove for this week.';
        } elseif (gameLocked($existingGame)) {
            $error = 'That game has already started — you can no longer remove this pick.';
        } else {
            $del = Database::pdo()->prepare(
                'DELETE p FROM picks p JOIN games g ON g.id = p.game_id
                 WHERE p.entry_id = ? AND g.season_year = ? AND g.week = ?'
            );
            $del->execute([$entry['id'], $season, $week]);
            flash('notice', 'Pick removed for Week ' . $week . '.');
            redirect("/survivor/picks.php?league_id=$leagueId&week=$week$entryQS");
        }
    } else {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $game = null;
        foreach ($weekGames as $g) {
            if ((int)$g['home_team_id'] === $teamId || (int)$g['away_team_id'] === $teamId) {
                $game = $g;
            }
        }
        if (!$game) {
            $error = 'Pick a team playing this week.';
        } elseif (gameLocked($game)) {
            $error = 'That game has already started.';
        } elseif (in_array($teamId, $usedTeamIds)) {
            $error = 'You already used that team earlier this season.';
        } else {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO picks (entry_id, game_id, team_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE team_id = VALUES(team_id)'
            );
            // Clear any other pick for this week first (only one team per week allowed).
            $del = Database::pdo()->prepare(
                'DELETE p FROM picks p JOIN games g ON g.id = p.game_id
                 WHERE p.entry_id = ? AND g.season_year = ? AND g.week = ?'
            );
            $del->execute([$entry['id'], $season, $week]);
            $stmt->execute([$entry['id'], $game['id'], $teamId]);
            flash('notice', 'Pick saved for Week ' . $week . '.');
            redirect("/survivor/picks.php?league_id=$leagueId&week=$week$entryQS");
        }
    }
}

$weeks = ScheduleRepo::weeksInSeason($season);
$pageTitle = 'Survivor Squad Pick';
require __DIR__ . '/../../templates/header.php';
?>

<div class="league-header" style="flex-direction:column;align-items:stretch;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h2><?= e($league['name']) ?><?= count($entries) > 1 ? ' — ' . e($entry['display_name']) : '' ?> &middot; Week <?= $week ?></h2>
      <div class="meta"><?= $status['alive'] ? 'Alive · ' . $status['weeks_survived'] . ' weeks survived · pick ahead any time' : 'Eliminated in Week ' . $status['eliminated_week'] ?></div>
    </div>
    <a href="/<?= $gameKey ?>/league.php?id=<?= $leagueId ?>&entry_id=<?= (int)$entry['id'] ?>"><button class="btn large">&larr; Back to Standings</button></a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
    <?php foreach ($weeks as $w): ?>
      <a href="?league_id=<?= $leagueId ?>&week=<?= $w ?><?= $entryQS ?>"><button class="btn <?= $w === $week ? '' : 'ghost' ?> small"><?= $w ?></button></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($n = flash('notice')): ?><div class="alert success"><?= e($n) ?></div><?php endif; ?>

<?php if (!$status['alive']): ?>
  <div class="empty-state">You were eliminated in Week <?= $status['eliminated_week'] ?>. Better luck next season!</div>
<?php elseif (!$weekGames): ?>
  <div class="empty-state">No games scheduled for Week <?= $week ?> yet.</div>
<?php else: ?>
<form method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" id="pickAction" value="pick">
  <div class="picks-grid">
    <?php foreach ($weekGames as $game):
      $locked = gameLocked($game);
    ?>
      <div class="game-row">
        <div class="matchup">
          <?= e($game['away_abbr']) ?> @ <?= e($game['home_abbr']) ?>
          <?php if ($game['spread']): ?><span class="spread"><?= e($game['favored_abbr']) ?> <?= fmtSpread($game['spread']) ?></span><?php endif; ?>
          <?php if ($locked): ?><span class="badge-locked">🔒 <?= e($game['status'] !== 'scheduled' ? ucfirst($game['status']) : 'Started') ?></span><?php endif; ?>
          <span class="kickoff-time" data-utc="<?= e(kickoffUtcIso($game['kickoff_at'])) ?>"><?= e(kickoffLabel($game['kickoff_at'])) ?></span>
          <?= renderLiveScore($game) ?>
        </div>
        <div class="pick-options">
          <?php foreach ([$game['away_team_id'] => $game['away_abbr'], $game['home_team_id'] => $game['home_abbr']] as $teamId => $abbr):
            $usedElsewhere = in_array($teamId, $usedTeamIds);
            $isPicked = $existingThisWeek && (int)$existingThisWeek['team_id'] === $teamId;
          ?>
            <label>
              <input type="radio" name="team_id" value="<?= $teamId ?>" style="display:none;"
                data-abbr="<?= e($abbr) ?>"
                <?= $isPicked ? 'checked' : '' ?>
                <?= ($locked || $usedElsewhere) ? 'disabled' : '' ?>
                onclick="handleTeamPick(this)">
              <span class="team-btn <?= $isPicked ? 'selected' : '' ?>" style="<?= $usedElsewhere ? 'opacity:.35;' : '' ?>"
                title="<?= $usedElsewhere ? 'Already used this season' : '' ?>"><?= e($abbr) ?><?= $usedElsewhere ? ' ✕' : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</form>
<script>
const survivorExistingTeamId = <?= $existingThisWeek ? (int)$existingThisWeek['team_id'] : 'null' ?>;
const survivorExistingAbbr = <?= $existingAbbr ? json_encode($existingAbbr) : 'null' ?>;
const survivorWeek = <?= (int)$week ?>;

function handleTeamPick(radio) {
  const teamId = parseInt(radio.value, 10);
  const actionField = document.getElementById('pickAction');

  if (survivorExistingTeamId !== null && teamId === survivorExistingTeamId) {
    // Clicking your already-highlighted team again offers to remove it,
    // rather than doing nothing — same confirm() pattern as changing a pick.
    if (!confirm('Remove your Week ' + survivorWeek + ' pick (' + survivorExistingAbbr + ')?')) {
      radio.checked = true; // stays selected, nothing changes
      return;
    }
    actionField.value = 'remove';
    radio.closest('form').submit();
    return;
  }

  if (survivorExistingTeamId !== null) {
    if (!confirm('Change your pick from ' + survivorExistingAbbr + ' to ' + radio.dataset.abbr + '?')) {
      // revert the visual selection back to the pick that's actually saved
      document.querySelectorAll('.team-btn').forEach(b => b.classList.remove('selected'));
      const orig = document.querySelector('input[type=radio][value="' + survivorExistingTeamId + '"]');
      if (orig) {
        orig.checked = true;
        orig.nextElementSibling.classList.add('selected');
      } else {
        radio.checked = false;
      }
      return;
    }
  }

  actionField.value = 'pick';
  document.querySelectorAll('.team-btn').forEach(b => b.classList.remove('selected'));
  radio.nextElementSibling.classList.add('selected');
  radio.checked = true;
  radio.closest('form').submit();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../templates/footer.php'; ?>
