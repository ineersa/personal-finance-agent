SELECT currency,is_draft,COUNT(*) AS record_count,
  MIN(transaction_date) AS first_date, MAX(transaction_date) AS latest_date
FROM financial_transaction GROUP BY currency,is_draft ORDER BY currency,is_draft;
