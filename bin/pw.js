/**
 * Browser helpers for checking the screens in headless Chromium (Playwright).
 *
 *   const { open } = require('./bin/pw');
 *   const s = await open(8111);                       // logs in as admin / admin
 *   await s.go('estasoft-migrate-logs');              // wp-admin/admin.php?page=...
 *   await s.shot('/tmp/logs.png');                    // full page screenshot
 *   console.log(s.problems());                        // console errors, page errors, failed requests (without avatars)
 *   await s.close();
 *
 * `s.page` is the Playwright page, for clicking and typing. Run from anywhere: require('playwright') is installed globally.
 */
const { chromium } = require( 'playwright' );

async function open( port, opts = {} ) {
	const browser = await chromium.launch();
	const context = await browser.newContext( { viewport: { width: opts.width || 1280, height: opts.height || 900 }, locale: opts.locale || 'en-US' } );
	const page = await context.newPage();
	const problems = [];
	const ignore = ( t ) => /gravatar|ERR_TUNNEL|favicon/.test( t );
	page.on( 'console', ( m ) => { if ( [ 'error', 'warning' ].includes( m.type() ) && ! ignore( m.text() ) ) { problems.push( m.type() + ': ' + m.text() ); } } );
	page.on( 'pageerror', ( e ) => problems.push( 'pageerror: ' + e.message ) );
	page.on( 'requestfailed', ( r ) => { if ( ! ignore( r.url() ) ) { problems.push( 'requestfailed: ' + r.url() ); } } );
	const base = `http://localhost:${ port }`;
	await page.goto( `${ base }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	return {
		page,
		base,
		problems: () => problems.slice(),
		go: async ( query, wait = 1200 ) => {
			await page.goto( `${ base }/wp-admin/admin.php?page=${ query }` );
			await page.waitForSelector( '#estm-app[aria-busy="false"]', { timeout: 15000 } ).catch( () => {} );
			await page.waitForTimeout( wait );
		},
		shot: ( file ) => page.screenshot( { path: file, fullPage: true } ),
		text: () => page.innerText( '#estm-app' ),
		close: () => browser.close(),
	};
}

module.exports = { open };
