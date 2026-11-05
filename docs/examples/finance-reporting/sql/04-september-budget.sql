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
), budget_input(category_name,budget) AS (
  VALUES ('Groceries',450.0), ('Dining & Takeout',100.0), ('Utilities',120.0)
), budget_categories AS (
  SELECT b.category_name,b.budget,COUNT(c.id) AS category_matches,MIN(c.id) AS category_id
  FROM budget_input b LEFT JOIN category c ON c.name = b.category_name
  GROUP BY b.category_name,b.budget
), actuals AS (
  SELECT category_id, COUNT(*) AS matched, COUNT(value) AS valued_count,
    COUNT(CASE WHEN value IS NOT NULL AND provisional = 1 THEN 1 END) AS provisional_count,
    SUM(CASE WHEN meaning = 'refund' THEN -value ELSE value END) AS actual_raw
  FROM valued WHERE meaning IN ('expense','refund') GROUP BY category_id
), combined AS (
  SELECT b.category_name AS category,b.budget,b.category_matches,
    COALESCE(a.matched,0) AS matched,COALESCE(a.valued_count,0) AS valued_count,
    COALESCE(a.provisional_count,0) AS provisional_count,
    (SELECT COUNT(*) FROM valued) AS scope_count,
    (SELECT COUNT(*) FROM valued WHERE meaning = 'unknown') AS unknown_count,
    CASE WHEN b.category_matches = 1 AND (SELECT COUNT(*) FROM valued) > 0
      AND COALESCE(a.matched,0) = COALESCE(a.valued_count,0)
      THEN COALESCE(a.actual_raw,0.0) END AS actual
  FROM budget_categories b LEFT JOIN actuals a ON a.category_id = b.category_id
)
SELECT category, category_matches, scope_count, unknown_count, matched, valued_count,
  matched-valued_count AS missing_count, provisional_count,
  ROUND(actual,2) AS actual, ROUND(budget,2) AS budget,
  ROUND(budget-actual,2) AS remaining,
  CASE WHEN budget > 0 THEN ROUND(100.0*actual/budget,1) END AS used_percent
FROM combined ORDER BY budget DESC,category;
