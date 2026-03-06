FROM node:22-slim
RUN apt-get update && apt-get install -y python3 make g++ curl && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://cli.claude.ai/install.sh | sh
ENV PATH="/root/.claude/bin:$PATH"
WORKDIR /app
COPY package*.json ./
RUN npm ci --production
COPY . .
EXPOSE 3000
CMD ["node", "server.js"]
