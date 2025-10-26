<?php
// Public/api/leave_records/_annual_quota.php
declare(strict_types=1);

/** 取得到職日與需排除年資的留停區間（目前一段） */
function _fetch_hire_and_exclusions(PDO $pdo, int $empId): array {
  $stmt = $pdo->prepare("SELECT hire_date, parental_leave_start, expected_return_date
                         FROM employees
                         WHERE emp_id=? AND (is_deleted IS NULL OR is_deleted=0)
                         LIMIT 1");
  $stmt->execute([$empId]);
  $row = $stmt->fetch();
  if (!$row || empty($row['hire_date'])) return [null, []];

  $hire = (new DateTime($row['hire_date']))->setTime(0,0,0);
  $ex = [];

  if (!empty($row['parental_leave_start']) && !empty($row['expected_return_date'])) {
    $s = (new DateTime($row['parental_leave_start']))->setTime(0,0,0);
    $e = (new DateTime($row['expected_return_date']))->setTime(0,0,0);
    if ($e >= $s) $ex[] = [$s, $e];
  }
  return [$hire, $ex];
}

function _overlap_days(DateTime $a1, DateTime $a2, DateTime $b1, DateTime $b2): int {
  if ($a2 < $a1 || $b2 < $b1) return 0;
  $s = max($a1, $b1); $e = min($a2, $b2);
  if ($e < $s) return 0;
  return (int)((int)$e->format('U') - (int)$s->format('U')) / 86400 + 1; // 含首尾
}
function _overlap_days_multi(DateTime $from, DateTime $to, array $exclusions): int {
  $sum = 0; foreach ($exclusions as [$s,$e]) $sum += _overlap_days($from, $to, $s, $e); return $sum;
}

/** 將名目門檻（hire+6m / +ky）按排除天數向後推移，直到收斂 */
function _shifted_threshold(DateTime $hire, DateTime $nominal, array $exclusions): DateTime {
  $cur = (clone $nominal)->setTime(0,0,0);
  while (true) {
    $excludeDays = _overlap_days_multi($hire, $cur, $exclusions);
    $shifted = (clone $nominal)->modify('+' . $excludeDays . ' days')->setTime(0,0,0);
    if ($shifted == $cur) return $cur;
    $cur = $shifted;
  }
}

/** 年資滿 k 年的年度配額（勞基法第38條） */
function _quota_level_for_years(int $years): int {
  if ($years < 1) return 0;
  if ($years === 1) return 7;
  if ($years === 2) return 10;
  if ($years === 3) return 14;
  if ($years >= 5) return min(30, 15 + ($years - 5)); // 5年起每年+1，上限30
  return 14; // 第4年仍14
}

/** 計算 from~to 在「月份」上的占比： (整月 + 部分月日/天) / 12 */
function _months_fraction(DateTime $from, DateTime $to): float {
  if ($to < $from) return 0.0;

  $y1=(int)$from->format('Y'); $m1=(int)$from->format('n'); $d1=(int)$from->format('j');
  $y2=(int)$to->format('Y');   $m2=(int)$to->format('n');   $d2=(int)$to->format('j');

  if ($y1 === $y2 && $m1 === $m2) {
    $daysIn = (int)$from->format('t'); $days = ($d2 - $d1 + 1);
    return ($days / $daysIn) / 12.0;
  }

  $daysInStart = (int)$from->format('t');
  $partStart   = ($daysInStart - $d1 + 1) / $daysInStart;
  $daysInEnd   = (int)$to->format('t');
  $partEnd     = $d2 / $daysInEnd;

  $startNextMonth = (clone $from)->modify('first day of next month')->setTime(0,0,0);
  $endPrevMonth   = (clone $to)->modify('first day of this month')->setTime(0,0,0);
  $fullMonths = 0;
  if ($endPrevMonth >= $startNextMonth) {
    $ym1 = (int)$startNextMonth->format('Y')*12 + (int)$startNextMonth->format('n');
    $ym2 = (int)$endPrevMonth->format('Y')*12 + (int)$endPrevMonth->format('n');
    $fullMonths = max(0, $ym2 - $ym1);
  }
  $months = $partStart + $fullMonths + $partEnd;
  return ($months / 12.0);
}

/** 曆年制特休日數（含：手動覆蓋、滿6月特例、周年級距比例、扣除育嬰留停期間） */
function calc_calendar_year_quota(PDO $pdo, int $empId, int $year): float {
  // 手動覆蓋優先
  try {
    $q = $pdo->prepare("SELECT annual_quota_days FROM leave_annual_quota WHERE emp_id=? AND year=? LIMIT 1");
    $q->execute([$empId, $year]);
    $v = $q->fetchColumn();
    if ($v !== false) return (float)$v;
  } catch (Throwable $e) {}

  [$hireDt, $exclusions] = _fetch_hire_and_exclusions($pdo, $empId);
  if (!$hireDt) return 0.0;

  $yStart = (new DateTime(sprintf('%04d-01-01', $year)))->setTime(0,0,0);
  $yEnd   = (new DateTime(sprintf('%04d-12-31', $year)))->setTime(0,0,0);
  if ($hireDt > $yEnd) return 0.0;

  $total = 0.0;

  // 滿6個月特例（不按比例）
  $nominal6m = (clone $hireDt)->modify('+6 months');
  $shift6m   = _shifted_threshold($hireDt, $nominal6m, $exclusions);
  if ($shift6m >= $yStart && $shift6m <= $yEnd) {
    $total += 3.0;
  }

  // 以「位移後周年」切段：滿1年起的級距 × 當年占比
  $cursor = clone $yStart;

  // 找到年初時最後一個不超過年初的「位移後周年」的年數 k
  $k = 0;
  while (true) {
    $nomK = (clone $hireDt)->modify('+' . ($k+1) . ' years');
    $shiftK = _shifted_threshold($hireDt, $nomK, $exclusions);
    if ($shiftK > $yStart) break;
    $k++;
  }

  if ($k >= 1) {
    $level = _quota_level_for_years($k);
    $nextNom   = (clone $hireDt)->modify('+' . ($k+1) . ' years');
    $nextShift = _shifted_threshold($hireDt, $nextNom, $exclusions);

    $segStart = clone $yStart;
    $segEnd   = (clone $nextShift)->modify('-1 day');
    if ($segEnd > $yEnd) $segEnd = clone $yEnd;
    if ($segStart <= $segEnd && $level > 0) {
      $total += $level * _months_fraction($segStart, $segEnd);
    }
    $cursor = $nextShift; $k++;
  } else {
    $firstShift1y = _shifted_threshold($hireDt, (clone $hireDt)->modify('+1 year'), $exclusions);
    $cursor = $firstShift1y; $k = 1;
  }

  while ($cursor <= $yEnd) {
    $level = _quota_level_for_years($k);
    if ($level <= 0) break;

    $nextNom   = (clone $hireDt)->modify('+' . ($k+1) . ' years');
    $nextShift = _shifted_threshold($hireDt, $nextNom, $exclusions);

    $segStart = clone $cursor;
    $segEnd   = (clone $nextShift)->modify('-1 day');
    if ($segEnd > $yEnd) $segEnd = clone $yEnd;

    if ($segStart <= $segEnd) {
      $total += $level * _months_fraction($segStart, $segEnd);
    }
    $cursor = $nextShift; $k++;
  }

  return round($total, 1); // 一位小數
}
