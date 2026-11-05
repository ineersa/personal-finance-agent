WITH RECURSIVE p AS (
  SELECT '2026-10-01' AS start_date, '2026-11-01' AS end_date, 'CAD' AS target_currency
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
), stats AS (
  SELECT COUNT(*) AS approved_count,
    COUNT(CASE WHEN meaning IN ('expense','refund') THEN 1 END) AS spending_matched,
    COUNT(CASE WHEN meaning IN ('expense','refund') THEN value END) AS spending_valued,
    COUNT(CASE WHEN meaning = 'income' THEN 1 END) AS income_matched,
    COUNT(CASE WHEN meaning = 'income' THEN value END) AS income_valued,
    COUNT(CASE WHEN meaning = 'unknown' THEN 1 END) AS unknown_count,
    COUNT(CASE WHEN meaning IN ('own_account_transfer','credit_card_payment') THEN 1 END) AS excluded_count,
    COUNT(CASE WHEN meaning IN ('expense','refund','income') AND value IS NOT NULL AND provisional = 1 THEN 1 END) AS provisional_count,
    SUM(CASE WHEN meaning = 'expense' THEN value ELSE 0 END) AS gross_raw,
    SUM(CASE WHEN meaning = 'refund' THEN value ELSE 0 END) AS refunds_raw,
    SUM(CASE WHEN meaning = 'income' THEN value ELSE 0 END) AS income_raw
  FROM valued
), complete AS (
  SELECT *,
    CASE WHEN approved_count > 0 AND spending_matched = spending_valued THEN gross_raw END AS gross,
    CASE WHEN approved_count > 0 AND spending_matched = spending_valued THEN refunds_raw END AS refunds,
    CASE WHEN approved_count > 0 AND spending_matched = spending_valued THEN gross_raw - refunds_raw END AS net,
    CASE WHEN approved_count > 0 AND income_matched = income_valued THEN income_raw END AS income
  FROM stats
)
SELECT p.start_date, p.end_date, p.target_currency,
  approved_count, spending_matched, spending_valued,
  spending_matched - spending_valued AS spending_missing,
  income_matched, income_valued, income_matched - income_valued AS income_missing,
  unknown_count, excluded_count, provisional_count,
  (SELECT COUNT(*) FROM financial_transaction d
   WHERE d.is_draft = 1 AND d.transaction_date >= p.start_date AND d.transaction_date < p.end_date) AS draft_count,
  ROUND(gross,2) AS gross_spending, ROUND(refunds,2) AS refunds,
  ROUND(net,2) AS net_spending, ROUND(income,2) AS income,
  ROUND(income-net,2) AS recorded_surplus,
  CASE WHEN income > 0 THEN ROUND(100.0*(income-net)/income,2) END AS recorded_surplus_percent
FROM complete CROSS JOIN p;
