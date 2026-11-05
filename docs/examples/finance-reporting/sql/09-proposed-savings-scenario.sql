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
), selected AS (
  SELECT c.name AS category,COUNT(*) AS matched,COUNT(value) AS valued_count,
    COUNT(CASE WHEN value IS NOT NULL AND provisional = 1 THEN 1 END) AS provisional_count,
    SUM(CASE WHEN meaning = 'refund' THEN -value ELSE value END) AS net_raw
  FROM valued v LEFT JOIN category c ON c.id = v.category_id
  WHERE meaning IN ('expense','refund') AND c.name IN ('Groceries','Dining & Takeout')
  GROUP BY c.id,c.name
), scenario AS (
  SELECT category,matched,valued_count,provisional_count,net_raw,
    CASE category WHEN 'Groceries' THEN 0.10 WHEN 'Dining & Takeout' THEN 0.20 END AS proposed_reduction
  FROM selected
)
SELECT category,matched,valued_count,matched-valued_count AS missing_count,provisional_count,
  (SELECT COUNT(*) FROM valued WHERE meaning = 'unknown') AS unknown_count,
  CASE WHEN matched = valued_count THEN ROUND(net_raw,2) END AS baseline,
  ROUND(proposed_reduction*100,0) AS proposed_reduction_percent,
  CASE WHEN matched = valued_count THEN ROUND(net_raw*proposed_reduction,2) END AS estimated_reduction,
  CASE WHEN matched = valued_count THEN ROUND(net_raw*(1-proposed_reduction),2) END AS proposed_budget
FROM scenario ORDER BY category;
