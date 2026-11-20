FROM python:3.12.12-slim-bookworm
# Official Microsoft package; ordinary PDF/XLSX parsers only, no OCR/vision plugins.
RUN pip install --no-cache-dir 'markitdown-mcp==0.0.1a8' 'markitdown==0.1.9' 'mcp==2.1.1' \
    && mkdir /statement-staging \
    && chmod 1777 /statement-staging \
    && useradd --uid 1000 --create-home converter
ENV MARKITDOWN_ENABLE_PLUGINS=false
USER converter
CMD ["markitdown-mcp", "--http", "--host", "0.0.0.0", "--port", "3001"]
