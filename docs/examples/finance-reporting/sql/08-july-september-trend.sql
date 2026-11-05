WITH RECURSIVE p AS (
  SELECT '2026-07-01' AS start_date, '2026-10-01' AS end_date, 'CAD' AS target_currency
), scoped AS (
  SELECT t.* FROM financial_transaction t CROSS JOIN p
  WHERE t.is_draft = 0
    AND t.transaction_date >= p.start_date AND t.transaction_date < p.end_date
), valued AS (
  SELECT t.*,
    CASE
      WHEN t.amount IS NULL OR t.currency IS NULL THEN NULL
      WHEN t.currency = p.target_currency THEN ABS(CAST(t.amount AS REAL))
      WHEN (t.currency = 'EUR' OR CAST(sr.rate AS REAL) > 0)
       AND (p.target_currency = 'EUR' OR CAST(tr.rate AS REAL) > 0)
      THEN ABS(CAST(t.amount AS REAL))
        * CASE WHEN p.target_currency = 'EUR' THEN 1.0 ELSE CAST(tr.rate AS REAL) END
        / CASE WHEN t.currency = 'EUR' THEN 1.0 ELSE CAST(sr.rate AS REAL) END
    END AS value,
    CASE WHEN t.currency = p.target_currency THEN 0
      WHEN (t.currency <> 'EUR' AND sr.is_provisional = 1)
        OR (p.target_currency <> 'EUR' AND tr.is_provisional = 1) THEN 1
      ELSE 0 END AS provisional
  FROM scoped t CROSS JOIN p
  LEFT JOIN currency_rate sr ON sr.currency = t.currency AND sr.day = t.transaction_date
  LEFT JOIN currency_rate tr ON tr.currency = p.target_currency AND tr.day = t.transaction_date
), calendar(month) AS (
  SELECT date(start_date,'start of month') FROM p WHERE start_date < end_date
  UNION ALL
  SELECT date(calendar.month,'+1 month') FROM calendar CROSS JOIN p
  WHERE date(calendar.month,'+1 month') < p.end_date
), monthly_raw AS (
  SELECT substr(transaction_date,1,7)||'-01' AS month,
    COUNT(*) AS approved_count,
    COUNT(CASE WHEN meaning = 'unknown' THEN 1 END) AS unknown_count,
    COUNT(CASE WHEN meaning IN ('expense','refund') THEN 1 END) AS spending_matched,
    COUNT(CASE WHEN meaning IN ('expense','refund') THEN value END) AS spending_valued,
    COUNT(CASE WHEN meaning = 'income' THEN 1 END) AS income_matched,
    COUNT(CASE WHEN meaning = 'income' THEN value END) AS income_valued,
    COUNT(CASE WHEN meaning IN ('expense','refund','income') AND value IS NOT NULL AND provisional = 1 THEN 1 END) AS provisional_count,
    SUM(CASE WHEN meaning = 'expense' THEN value ELSE 0 END) AS gross_raw,
    SUM(CASE WHEN meaning = 'refund' THEN value ELSE 0 END) AS refunds_raw,
    SUM(CASE WHEN meaning = 'income' THEN value ELSE 0 END) AS income_raw
  FROM valued GROUP BY substr(transaction_date,1,7)
), monthly AS (
  SELECT c.month,COALESCE(m.approved_count,0) AS approved_count,
    COALESCE(m.unknown_count,0) AS unknown_count,
    COALESCE(m.spending_matched,0) AS spending_matched,COALESCE(m.spending_valued,0) AS spending_valued,
    COALESCE(m.income_matched,0) AS income_matched,COALESCE(m.income_valued,0) AS income_valued,
    COALESCE(m.provisional_count,0) AS provisional_count,
    CASE WHEN m.approved_count > 0 AND m.spending_matched = m.spending_valued THEN m.gross_raw END AS gross,
    CASE WHEN m.approved_count > 0 AND m.spending_matched = m.spending_valued THEN m.refunds_raw END AS refunds,
    CASE WHEN m.approved_count > 0 AND m.spending_matched = m.spending_valued THEN m.gross_raw-m.refunds_raw END AS net,
    CASE WHEN m.approved_count > 0 AND m.income_matched = m.income_valued THEN m.income_raw END AS income
  FROM calendar c LEFT JOIN monthly_raw m ON m.month = c.month
), compared AS (
  SELECT *, LAG(month) OVER (ORDER BY month) AS prior_month,
    LAG(gross) OVER (ORDER BY month) AS prior_gross,
    LAG(refunds) OVER (ORDER BY month) AS prior_refunds,
    LAG(net) OVER (ORDER BY month) AS prior_net
  FROM monthly
)
SELECT month, approved_count, unknown_count, spending_matched,
  spending_matched-spending_valued AS spending_missing,
  income_matched-income_valued AS income_missing, provisional_count,
  ROUND(gross,2) AS gross_spending, ROUND(refunds,2) AS refunds,
  ROUND(net,2) AS net_spending, ROUND(income,2) AS income,
  ROUND(income-net,2) AS recorded_surplus,
  CASE WHEN prior_month = date(month,'-1 month') THEN ROUND(net-prior_net,2) END AS net_change,
  CASE WHEN prior_month = date(month,'-1 month') AND prior_net > 0
    THEN ROUND(100.0*(net-prior_net)/prior_net,2) END AS net_change_percent,
  CASE WHEN prior_month = date(month,'-1 month') THEN ROUND(gross-prior_gross,2) END AS gross_change,
  CASE WHEN prior_month = date(month,'-1 month') THEN ROUND(refunds-prior_refunds,2) END AS refunds_change
FROM compared ORDER BY month;
