import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

puppeteer.use(StealthPlugin());

export async function scrapeBilesuParadize(options = {}) {
  const maxPages = options.maxPages || 3;
  const timeout = options.timeout || 45000;
  
  const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-accelerated-2d-canvas',
      '--no-first-run',
      '--no-zygote',
      '--disable-gpu',
      '--disable-blink-features=AutomationControlled',
      '--lang=lv-LV,lv,en-US,en'
    ]
  });

  const events = [];
  const interceptedApiEvents = [];

  try {
    const page = await browser.newPage();

    await page.setViewport({ width: 1440, height: 900 });
    await page.setUserAgent(
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );

    await page.setExtraHTTPHeaders({
      'Accept-Language': 'lv-LV,lv;q=0.9,en-US;q=0.8,en;q=0.7'
    });

    // Intercept any JSON API responses that contain events
    page.on('response', async (response) => {
      try {
        const url = response.url();
        const contentType = response.headers()['content-type'] || '';
        if (contentType.includes('application/json') && (url.includes('event') || url.includes('search') || url.includes('performances') || url.includes('graphql'))) {
          const json = await response.json();
          if (json) {
            interceptedApiEvents.push({ url, data: json });
          }
        }
      } catch (err) {
        // Ignore response parsing errors for non-JSON or stream aborts
      }
    });

    console.log('[Puppeteer] Navigating to https://www.bilesuparadize.lv/lv ...');
    
    await page.goto('https://www.bilesuparadize.lv/lv', {
      waitUntil: 'networkidle2',
      timeout: timeout
    });

    // Wait a short moment in case Cloudflare challenge is running or page is rendering
    await new Promise(r => setTimeout(r, 4000));

    // Check if Cloudflare turnstile / challenge iframe exists and wait if needed
    const cfTitle = await page.title();
    console.log('[Puppeteer] Page Title:', cfTitle);

    // Extract events from DOM
    const extractedDomEvents = await page.evaluate(() => {
      const items = [];
      const cards = document.querySelectorAll(
        '.event-card, .performance-card, .event-item, article, a[href*="/event/"], a[href*="/performance/"]'
      );

      cards.forEach((card) => {
        try {
          const titleEl = card.querySelector('.title, .event-title, .name, h2, h3, h4, strong');
          const title = titleEl ? titleEl.textContent.trim() : '';
          if (!title || title.length < 3) return;

          const linkEl = card.tagName === 'A' ? card : card.querySelector('a');
          const link = linkEl ? linkEl.href : '';

          const dateEl = card.querySelector('.date, .time, .event-date, time, .datetime');
          const dateText = dateEl ? dateEl.textContent.trim() : '';

          const venueEl = card.querySelector('.venue, .place, .location, .hall');
          const venueText = venueEl ? venueEl.textContent.trim() : '';

          const priceEl = card.querySelector('.price, .event-price, .cost');
          const priceText = priceEl ? priceEl.textContent.trim() : '';

          const imgEl = card.querySelector('img');
          const imageUrl = imgEl ? (imgEl.src || imgEl.dataset.src || '') : '';

          const descEl = card.querySelector('.description, .subtitle, p');
          const description = descEl ? descEl.textContent.trim() : '';

          items.push({
            title,
            link,
            dateText,
            venueText,
            priceText,
            imageUrl,
            description
          });
        } catch (e) {}
      });

      return items;
    });

    console.log(`[Puppeteer] Extracted ${extractedDomEvents.length} events from DOM`);

    // Process & normalize events
    for (const item of extractedDomEvents) {
      if (!item.title) continue;

      events.push({
        title: item.title,
        source_url: item.link || 'https://www.bilesuparadize.lv/lv',
        ticket_url: item.link || 'https://www.bilesuparadize.lv/lv',
        venue_name: item.venueText || 'Rīga',
        city: 'Rīga',
        date_text: item.dateText,
        price_text: item.priceText,
        image_url: item.imageUrl,
        description: item.description,
        source_name: 'Biļešu Paradīze',
        source_slug: 'bilesu-paradize'
      });
    }

    // Also check search / events catalogue page if we need more
    try {
      console.log('[Puppeteer] Navigating to https://www.bilesuparadize.lv/lv/events/all ...');
      await page.goto('https://www.bilesuparadize.lv/lv/events/all', {
        waitUntil: 'networkidle2',
        timeout: 20000
      }).catch(() => null);

      await new Promise(r => setTimeout(r, 3000));

      const catalogueEvents = await page.evaluate(() => {
        const items = [];
        const cards = document.querySelectorAll('.event-card, .performance-card, article, a[href*="/event/"]');
        cards.forEach((card) => {
          try {
            const titleEl = card.querySelector('.title, .event-title, .name, h2, h3, h4, strong');
            const title = titleEl ? titleEl.textContent.trim() : '';
            if (!title || title.length < 3) return;

            const linkEl = card.tagName === 'A' ? card : card.querySelector('a');
            const link = linkEl ? linkEl.href : '';

            const dateEl = card.querySelector('.date, .time, .event-date, time');
            const dateText = dateEl ? dateEl.textContent.trim() : '';

            const venueEl = card.querySelector('.venue, .place, .location');
            const venueText = venueEl ? venueEl.textContent.trim() : '';

            const priceEl = card.querySelector('.price, .event-price');
            const priceText = priceEl ? priceEl.textContent.trim() : '';

            const imgEl = card.querySelector('img');
            const imageUrl = imgEl ? (imgEl.src || imgEl.dataset.src || '') : '';

            items.push({
              title,
              link,
              dateText,
              venueText,
              priceText,
              imageUrl
            });
          } catch (e) {}
        });
        return items;
      });

      for (const item of catalogueEvents) {
        if (!item.title || events.some(e => e.title === item.title)) continue;
        events.push({
          title: item.title,
          source_url: item.link || 'https://www.bilesuparadize.lv/lv',
          ticket_url: item.link || 'https://www.bilesuparadize.lv/lv',
          venue_name: item.venueText || 'Rīga',
          city: 'Rīga',
          date_text: item.dateText,
          price_text: item.priceText,
          image_url: item.imageUrl,
          source_name: 'Biļešu Paradīze',
          source_slug: 'bilesu-paradize'
        });
      }
    } catch (catErr) {
      console.log('[Puppeteer] Catalogue navigation info:', catErr.message);
    }

  } catch (error) {
    console.error('[Puppeteer] Scrape error:', error);
  } finally {
    await browser.close();
  }

  return {
    success: true,
    total: events.length,
    events: events,
    intercepted_apis_count: interceptedApiEvents.length
  };
}

// If executed directly from CLI
if (process.argv[1] && process.argv[1].endsWith('bilesuparadize.js')) {
  scrapeBilesuParadize().then((res) => {
    console.log(JSON.stringify(res, null, 2));
    process.exit(0);
  }).catch((err) => {
    console.error(err);
    process.exit(1);
  });
}
