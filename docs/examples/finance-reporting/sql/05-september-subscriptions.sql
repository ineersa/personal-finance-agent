WITH RECURSIVE p AS (
  SELECT '2026-09-01' AS start_date, '2026-10-01' AS end_date, 'CAD' AS target_currency
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
)
SELECT description,currency,COUNT(*) AS matched,COUNT(value) AS valued_count,
  COUNT(*)-COUNT(value) AS missing_count,
  COUNT(CASE WHEN value IS NOT NULL AND provisional = 1 THEN 1 END) AS provisional_count,
  ROUND(SUM(CASE WHEN meaning = 'refund' THEN -ABS(CAST(amount AS REAL)) ELSE ABS(CAST(amount AS REAL)) END),2) AS original_net,
  CASE WHEN COUNT(*) = COUNT(value)
    THEN ROUND(SUM(CASE WHEN meaning = 'refund' THEN -value ELSE value END),2) END AS net_cad
FROM valued WHERE recurrence = 'subscription' AND meaning IN ('expense','refund')
GROUP BY description,currency ORDER BY net_cad DESC,description;
