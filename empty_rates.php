<?php
/**
 * TMS — empty_rates.php
 * The empty-container rate book. Rates are keyed by carrier/line +
 * from -> to. We store the 12m rate; the 6m rate is shown as
 * 12m × the 6m factor (editable in Rates & Costs).
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Empty Rates';
$pageSubtitle = 'Empty-container rate book';
$factor       = setting_num('empty_6m_factor', 0.5);

/* ---------------- Handle actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_empty') {
        $id      = (int) post('id', 0);
        $carrier = post('carrier');
        $from    = post('from_location');
        $to      = post('to_location');
        $rate    = clean_number(post('rate_12m'));
        $notes   = post('notes');

        if ($carrier === '' || $from === '' || $to === '' || $rate === null) {
            flash('error', 'Carrier, From, To and a valid 12m rate are all required.');
            redirect('empty_rates.php' . ($id ? '?edit=' . $id : ''));
        }

        if ($id > 0) {
            dbq("UPDATE empty_rates SET carrier=?, from_location=?, to_location=?, rate_12m=?, notes=? WHERE id=?",
                array($carrier, $from, $to, $rate, ($notes !== '' ? $notes : null), $id));
            audit('update', 'empty_rate', $id, $carrier . ': ' . $from . ' → ' . $to);
            flash('success', 'Updated empty rate for ' . $carrier . '.');
        } else {
            $newId = db_insert("INSERT INTO empty_rates (carrier, from_location, to_location, rate_12m, notes) VALUES (?,?,?,?,?)",
                array($carrier, $from, $to, $rate, ($notes !== '' ? $notes : null)));
            audit('create', 'empty_rate', $newId, $carrier . ': ' . $from . ' → ' . $to);
            flash('success', 'Added empty rate for ' . $carrier . '.');
        }
        redirect('empty_rates.php');
    }

    if ($action === 'toggle_empty') {
        $id  = (int) post('id', 0);
        $row = db_row("SELECT carrier, active FROM empty_rates WHERE id = ?", array($id));
        if ($row) {
            $newState = ((int) $row['active'] === 1) ? 0 : 1;
            dbq("UPDATE empty_rates SET active = ? WHERE id = ?", array($newState, $id));
            audit($newState ? 'activate' : 'deactivate', 'empty_rate', $id, $row['carrier']);
            flash('success', 'Rate is now ' . ($newState ? 'active' : 'inactive') . '.');
        }
        redirect('empty_rates.php');
    }
}

/* ---------------- Edit mode ---------------- */
$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM empty_rates WHERE id = ?", array($editId)) : null;

/* ---------------- Load list, grouped by carrier ---------------- */
$rows = db_all("SELECT * FROM empty_rates ORDER BY carrier ASC, rate_12m DESC");
$total = count($rows);
$carriers = array();
foreach ($rows as $r) { $carriers[$r['carrier']] = true; }

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/rates.php">Rates &amp; Costs</a>
  <a href="<?= BASE_URL ?>/clients.php">Clients</a>
  <a href="<?= BASE_URL ?>/destinations.php">Destinations</a>
  <a href="<?= BASE_URL ?>/empty_rates.php" class="active">Empty Rates</a>
</div>

<div class="page-head">
  <div>
    <h1>Empty Rates</h1>
    <p><?= $total ?> rates across <?= count($carriers) ?> carriers. You enter the <b>12m</b> rate — the 6m rate is
       always 12m × <?= num($factor, 2) ?> (change that factor in <a href="<?= BASE_URL ?>/rates.php">Rates &amp; Costs → Client Rates</a>).</p>
  </div>
</div>

<!-- Add / Edit form -->
<div class="card" id="empty-form">
  <h3><?= $edit ? '✎ Edit empty rate' : '＋ Add an empty rate' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/empty_rates.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_empty">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row">
        <label class="lbl">Carrier / line <span class="req">*</span></label>
        <input type="text" name="carrier" required value="<?= $edit ? e($edit['carrier']) : '' ?>"
               placeholder="e.g. Grindrod, CMA, Hapag-Lloyd, ONE, DCP" list="carrier-list">
        <datalist id="carrier-list">
          <?php foreach (array_keys($carriers) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <div class="form-row">
        <label class="lbl">From <span class="req">*</span></label>
        <input type="text" name="from_location" required value="<?= $edit ? e($edit['from_location']) : '' ?>"
               placeholder="e.g. CT Terminals (use 'Any' for a flat rate)">
      </div>
      <div class="form-row">
        <label class="lbl">To <span class="req">*</span></label>
        <input type="text" name="to_location" required value="<?= $edit ? e($edit['to_location']) : '' ?>"
               placeholder="e.g. DCP (use 'Any' for a flat rate)">
      </div>
      <div class="form-row">
        <label class="lbl">12m rate <span class="req">*</span></label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="rate_12m"
          value="<?= $edit ? e($edit['rate_12m']) : '' ?>" placeholder="1500.00" id="rate12"></div>
        <div class="hint">6m will be R<span id="rate6">—</span> automatically</div>
      </div>
      <div class="form-row">
        <label class="lbl">Notes</label>
        <input type="text" name="notes" value="<?= $edit ? e($edit['notes']) : '' ?>" placeholder="optional (e.g. non-genset)">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add empty rate' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/empty_rates.php" class="btn ghost">Cancel</a><?php endif; ?>
      <span class="note">Tip: set From and To to “Any” for a flat per-carrier rate (like DCP).</span>
    </div>
  </form>
</div>

<!-- Rate book table -->
<div class="card">
  <h3>◳ Empty rate book</h3>
  <p class="sub">Grouped by carrier. 6m is derived from 12m.</p>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Carrier</th>
          <th>From</th>
          <th>To</th>
          <th class="num">12m</th>
          <th class="num">6m (½)</th>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php $lastCarrier = null; foreach ($rows as $r):
          $six = round($r['rate_12m'] * $factor, 2);
          $newGroup = ($r['carrier'] !== $lastCarrier);
          $lastCarrier = $r['carrier'];
        ?>
          <tr class="<?= ($editId === (int) $r['id']) ? 'editing' : '' ?>">
            <td><?= $newGroup ? '<b>' . e($r['carrier']) . '</b>' : '<span class="muted">' . e($r['carrier']) . '</span>' ?></td>
            <td><?= e($r['from_location']) ?></td>
            <td><?= e($r['to_location']) ?>
              <?php if ($r['notes']): ?><br><span class="hint"><?= e($r['notes']) ?></span><?php endif; ?>
            </td>
            <td class="num"><b><?= num($r['rate_12m'], 2) ?></b></td>
            <td class="num muted"><?= num($six, 2) ?></td>
            <td>
              <?php if ((int) $r['active'] === 1): ?>
                <span class="badge good">Active</span>
              <?php else: ?>
                <span class="badge muted">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="row-actions">
                <a href="<?= BASE_URL ?>/empty_rates.php?edit=<?= (int) $r['id'] ?>#empty-form" class="btn ghost sm">Edit</a>
                <form method="post" action="<?= BASE_URL ?>/empty_rates.php" style="display:inline"
                      onsubmit="return confirm('<?= (int)$r['active']===1 ? 'Deactivate' : 'Reactivate' ?> this rate?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_empty">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" class="btn ghost sm"><?= (int) $r['active'] === 1 ? 'Off' : 'On' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Live 6m preview in the form
(function(){
  var f = <?= json_encode($factor) ?>;
  var r12 = document.getElementById('rate12');
  var r6  = document.getElementById('rate6');
  function upd(){
    var v = parseFloat(r12.value);
    r6.textContent = isNaN(v) ? '—' : (v * f).toFixed(2);
  }
  if (r12) { r12.addEventListener('input', upd); upd(); }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
