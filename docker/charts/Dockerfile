FROM node:22.21.1-alpine
RUN npm install --global --omit=dev @antv/mcp-server-chart@0.9.10
USER node
CMD ["mcp-server-chart", "--transport", "streamable", "--host", "0.0.0.0", "--port", "1122", "--endpoint", "/mcp"]
