const puppeteer = require('puppeteer-core');
const BASE = 'https://demo.nicov1.fr';
const HIDE = '.fronote-demo-banner,#cookie-consent-banner{display:none!important} body{padding-bottom:0!important}';
const desk = [
  ['direction', '/admin/dashboard.php', 'direction-pilotage'],
  ['direction', '/accueil/accueil.php', 'direction-accueil'],
  ['prof', '/modules/emploi_du_temps/emploi_du_temps.php', 'prof-emploi-du-temps'],
  ['prof', '/modules/notes/notes.php', 'prof-notes'],
  ['prof', '/modules/appel/appel.php', 'prof-appel'],
  ['prof', '/modules/cahierdetextes/cahierdetextes.php', 'prof-cahier-de-textes'],
  ['prof', '/modules/messagerie/index.php', 'prof-messagerie'],
  ['eleve', '/modules/notes/notes.php', 'eleve-notes'],
  ['eleve', '/accueil/accueil.php', 'eleve-accueil'],
  ['eleve', '/modules/emploi_du_temps/emploi_du_temps.php', 'eleve-emploi-du-temps'],
  ['parent', '/accueil/accueil.php', 'parent-accueil'],
  ['parent', '/modules/absences/absences.php', 'parent-absences'],
  ['cpe', '/modules/absences/absences.php', 'cpe-absences'],
  ['cpe', '/accueil/accueil.php', 'cpe-accueil'],
];
const mobile = [['eleve', '/accueil/accueil.php', 'mobile-eleve'], ['parent', '/modules/notes/notes.php', 'mobile-parent-notes']];
(async () => {
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox', '--ignore-certificate-errors', '--lang=fr-FR'] });
  const ctxs = {};
  async function pageFor(role, vp) {
    const ctx = await browser.createBrowserContext();
    const page = await ctx.newPage();
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'fr-FR,fr;q=0.9' });
    await page.setViewport(vp);
    await page.goto(`${BASE}/demo/?role=${role}`, { waitUntil: 'networkidle2', timeout: 60000 });
    try { await page.click('#cookie-accept-all'); } catch (e) {}
    return [ctx, page];
  }
  async function shoot(page, path, out) {
    await page.goto(BASE + path, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.addStyleTag({ content: HIDE });
    await new Promise(r => setTimeout(r, 900));
    await page.screenshot({ path: `/root/shots/mkt/${out}.webp`, type: 'webp', quality: 82 });
    console.log(out, page.url().replace(BASE, ''));
  }
  for (const [role, path, out] of desk) {
    if (!ctxs[role]) ctxs[role] = await pageFor(role, { width: 1280, height: 800, deviceScaleFactor: 1.5 });
    await shoot(ctxs[role][1], path, out);
  }
  for (const [role, path, out] of mobile) {
    const [ctx, page] = await pageFor(role, { width: 390, height: 780, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    await shoot(page, path, out); await ctx.close();
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
