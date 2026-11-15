FROM node:24.22.2-bookworm AS build
WORKDIR /unovis
RUN git init . \
    && git remote add origin https://github.com/f5/unovis.git \
    && git fetch --depth=1 origin 002633e531a70cc27b88ebf68a4e48d1d4abbaad \
    && git checkout --detach FETCH_HEAD \
    && corepack enable \
    && corepack prepare pnpm@10.23.0 --activate \
    && pnpm install --filter @unovis/mcp... --frozen-lockfile --ignore-scripts \
    && pnpm build:mcp

FROM node:24.22.2-bookworm
WORKDIR /unovis
COPY --from=build /unovis/package.json ./package.json
COPY --from=build /unovis/node_modules ./node_modules
COPY --from=build /unovis/packages ./packages
RUN mkdir /chart-output && chmod 0777 /chart-output
ENV UNOVIS_MCP_NO_DOWNLOAD=1
USER node
CMD ["node", "packages/mcp/dist/cli.js", "--transport", "http", "--host", "0.0.0.0", "--port", "3737", "--endpoint", "/mcp", "--allow-write-dir", "/chart-output"]
