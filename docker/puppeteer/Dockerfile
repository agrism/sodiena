FROM ghcr.io/puppeteer/puppeteer:22

WORKDIR /app

USER root

COPY package*.json ./

RUN npm install --omit=dev

COPY . .

RUN chown -R pptruser:pptruser /app

USER pptruser

ENV PORT=3000
ENV NODE_ENV=production
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/google-chrome-stable

EXPOSE 3000

CMD ["node", "server.js"]
