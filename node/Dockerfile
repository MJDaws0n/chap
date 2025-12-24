FROM node:20-alpine

# Install Docker CLI (for controlling Docker on host via socket mount) and git for cloning repos
RUN apk add --no-cache docker-cli docker-cli-compose git

# Create app directory
WORKDIR /app

# Copy package files
COPY package.json ./

# Install dependencies
RUN npm install --production

# Copy source code
COPY src/ ./src/

# Create persistent data directory structure
# This will be mounted as a volume in production
RUN mkdir -p /data/apps /data/repos /data/builds /data/compose /data/volumes /data/logs

# Environment variables
ENV NODE_ENV=production
ENV CHAP_SERVER_URL=ws://chap-server:8081
ENV CHAP_DATA_DIR=/data
ENV NODE_ID=
ENV NODE_TOKEN=
ENV BROWSER_WS_PORT=6002
ENV BROWSER_WS_HOST=0.0.0.0

# Expose browser WebSocket port for direct log streaming
EXPOSE 6002

# Run the agent
CMD ["npm", "start"]
