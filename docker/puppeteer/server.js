import express from 'express';
import cors from 'cors';
import { scrapeBilesuParadize } from './bilesuparadize.js';

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json());

app.get('/health', (req, res) => {
  res.json({ status: 'ok', service: 'puppeteer-scraper', timestamp: new Date().toISOString() });
});

app.all('/scrape/bilesuparadize', async (req, res) => {
  console.log(`[API] Received scrape request for Biļešu Paradīze from ${req.ip}`);
  try {
    const result = await scrapeBilesuParadize({
      maxPages: req.query.maxPages ? parseInt(req.query.maxPages, 10) : 3,
      timeout: req.query.timeout ? parseInt(req.query.timeout, 10) : 45000
    });
    res.json(result);
  } catch (err) {
    console.error('[API] Scraper error:', err);
    res.status(500).json({
      success: false,
      error: err.message,
      events: []
    });
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`🚀 Puppeteer Scraper microservice running on http://0.0.0.0:${PORT}`);
});
