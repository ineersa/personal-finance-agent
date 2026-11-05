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
), grouped AS (
  SELECT v.category_id, COALESCE(c.name,'Uncategorized (no category)') AS category,
    COUNT(*) AS matched, COUNT(value) AS valued_count,
    COUNT(CASE WHEN value IS NOT NULL AND provisional = 1 THEN 1 END) AS provisional_count,
    SUM(CASE WHEN meaning = 'refund' THEN -value ELSE value END) AS net_raw
  FROM valued v LEFT JOIN category c ON c.id = v.category_id
  WHERE meaning IN ('expense','refund')
  GROUP BY v.category_id, c.name
), coverage AS (
  SELECT SUM(matched) AS matched, SUM(valued_count) AS valued_count,
    SUM(provisional_count) AS provisional_count, SUM(net_raw) AS total_raw,
    (SELECT COUNT(*) FROM valued WHERE meaning = 'unknown') AS unknown_count
  FROM grouped
), ranked AS (
  SELECT *, ROW_NUMBER() OVER (ORDER BY net_raw DESC, category_id) AS rank
  FROM grouped
), buckets AS (
  SELECT CASE WHEN rank <= 5 THEN category_id ELSE -999999 END AS bucket_id,
    CASE WHEN rank <= 5 THEN category ELSE 'Other categories' END AS bucket_label,
    MIN(rank) AS position, SUM(matched) AS matched,
    SUM(valued_count) AS valued_count, SUM(net_raw) AS net_raw
  FROM ranked GROUP BY bucket_id, bucket_label
)
SELECT b.bucket_label AS category, b.matched, b.valued_count,
  b.matched-b.valued_count AS category_missing,
  c.matched AS total_matched, c.valued_count AS total_valued,
  c.matched-c.valued_count AS total_missing, c.provisional_count, c.unknown_count,
  CASE WHEN b.matched = b.valued_count THEN ROUND(b.net_raw,2) END AS net_spending,
  CASE WHEN c.matched = c.valued_count AND c.unknown_count = 0 AND c.total_raw > 0
    THEN ROUND(100.0*b.net_raw/c.total_raw,2) END AS share_percent,
  CASE WHEN c.matched = c.valued_count THEN ROUND(c.total_raw,2) END AS total_spending
FROM buckets b CROSS JOIN coverage c ORDER BY b.position;
