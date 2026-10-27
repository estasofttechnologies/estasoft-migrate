<?php
/**
 * Page shell of every Estasoft Migrate screen. The screen itself is drawn by JavaScript into #estm-app.
 *
 * Variables from Admin::render(): $id, $screen (title, menu, slug), $links.
 *
 * @package Estasoft\Migrate
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap estm-wrap">
	<h1 class="screen-reader-text"><?php echo esc_html( $screen['title'] ); ?></h1>
	<hr class="wp-header-end">

	<div class="estm" data-screen="<?php echo esc_attr( '' === $id ? 'overview' : $id ); ?>">
		<header class="est-head">
			<svg class="est-logo" viewBox="0 0 1525 1525" width="44" height="44" aria-hidden="true" focusable="false"><rect width="1525" height="1525" fill="#fed431"/><path fill="#1c1c1c" d="M277.1 232.25H1248.1V412.5H457.2V712.05A335 335 0 0 1 613.7 673.25H1181.4V851.75H613.7A156.5 156.5 0 0 0 457.2 1008.25V1112.25H1248.1V1292.5H277.1Z"/></svg>
			<div>
				<div class="est-name"><?php esc_html_e( 'Estasoft Migration & Database Sanitizer', 'estasoft-migrate' ); ?></div>
				<div class="est-by">
					<?php
					printf(
						/* translators: %s: plugin version */
						esc_html__( 'by ESTASOFT TECHNOLOGIES · Version %s', 'estasoft-migrate' ),
						esc_html( ESTASOFT_MIGRATE_VERSION )
					);
					?>
				</div>
			</div>
			<span class="sp"></span>
			<span class="badge b-free"><?php esc_html_e( '100% free · GPL v2 or later', 'estasoft-migrate' ); ?></span>
			<?php if ( ! empty( $links['docs'] ) ) : ?>
				<a href="<?php echo esc_url( $links['docs'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'estasoft-migrate' ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $links['support'] ) ) : ?>
				<a href="<?php echo esc_url( $links['support'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'estasoft-migrate' ); ?></a>
			<?php endif; ?>
		</header>

		<div id="estm-app" class="est-page" aria-busy="true">
			<p class="estm-boot"><?php esc_html_e( 'Loading…', 'estasoft-migrate' ); ?></p>
		</div>

		<noscript>
			<div class="note warn" style="margin: 24px">
				<div><b><?php esc_html_e( 'JavaScript is turned off.', 'estasoft-migrate' ); ?></b> <?php esc_html_e( 'These screens need JavaScript to work. Turn it on in your browser and reload the page. The command line works without it: run “wp estasoft-migrate” for the list of commands.', 'estasoft-migrate' ); ?></div>
			</div>
		</noscript>

		<footer class="est-foot">
			<span>
				<?php
				printf(
					/* translators: %s: plugin version */
					esc_html__( 'Estasoft Migration & Database Sanitizer %s', 'estasoft-migrate' ),
					esc_html( ESTASOFT_MIGRATE_VERSION )
				);
				?>
			</span>
			<span><?php esc_html_e( 'Free software, GPL v2 or later', 'estasoft-migrate' ); ?></span>
			<span>© ESTASOFT TECHNOLOGIES</span>
		</footer>
	</div>
</div>
