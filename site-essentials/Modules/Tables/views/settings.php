<?php
/**
 * Tables Module Settings View
 *
 * Reference and verification only — this module stores no options.
 *
 * @package    SiteEssentials
 * @subpackage Modules\Tables
 * @version    1.0.0
 *
 * Variables available:
 * @var array $tokens     Token name => [ description, required ]
 * @var array $behaviours Behaviour class => description
 * @var array $modifiers  Modifier class => description
 *
 * v1.0 | 2026-08-28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<p class="description">
	<?php esc_html_e( 'Tables are styled automatically. Colours come from three CSS variables mapped once in Breakdance global CSS; the mobile layout is chosen per table in the block sidebar.', 'site-essentials' ); ?>
</p>

<h3><?php esc_html_e( 'Site tokens', 'site-essentials' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Map these in Breakdance global CSS. The first three are required; the rest already derive from them and only need setting where the site has a better value of its own. The swatches below show what this site currently resolves them to — grey and square means nothing has been mapped yet.', 'site-essentials' ); ?>
</p>

<table class="scos-form">
	<tbody>
	<?php foreach ( $tokens as $token => $meta ) : ?>
		<tr>
			<th>
				<label><?php echo esc_html( $meta[0] ); ?></label>
				<div class="scos-form__slug"><?php echo esc_html( $token ); ?></div>
			</th>
			<td>
				<span class="scos-tables__swatch" style="background: var(<?php echo esc_attr( $token ); ?>)" aria-hidden="true"></span>
				<?php if ( $meta[1] ) : ?>
					<strong><?php esc_html_e( 'Required', 'site-essentials' ); ?></strong>
				<?php else : ?>
					<?php esc_html_e( 'Optional — derived if unset', 'site-essentials' ); ?>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?php esc_html_e( 'Mobile layout', 'site-essentials' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Pick one per table from the block sidebar under Styles. Below 680px the table switches to that layout; above it, every table looks the same.', 'site-essentials' ); ?>
</p>

<table class="scos-form">
	<tbody>
	<?php foreach ( $behaviours as $class => $desc ) : ?>
		<tr>
			<th><div class="scos-form__slug"><?php echo esc_html( $class ); ?></div></th>
			<td><?php echo esc_html( $desc ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?php esc_html_e( 'Modifiers', 'site-essentials' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Stack any of these on top of the chosen layout by typing them into the block\'s Additional CSS class field, separated by spaces.', 'site-essentials' ); ?>
</p>

<table class="scos-form">
	<tbody>
	<?php foreach ( $modifiers as $class => $desc ) : ?>
		<tr>
			<th><div class="scos-form__slug"><?php echo esc_html( $class ); ?></div></th>
			<td><?php echo esc_html( $desc ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
