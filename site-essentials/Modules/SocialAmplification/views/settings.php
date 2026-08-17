<?php
/**
 * Social Amplification — Settings page view
 *
 * Rendered by SocialAmplification_Module::render_settings() via Admin_UI.
 * SCOS-SA-PASS1 — full UI rebuild to SCOS design system (Template A: tabbed settings).
 * SCOS-SA-PASS2 — per-post-type config tab, channel split, global framing, slot-gap-days.
 *
 * @package SiteEssentials
 * v1.2 | 2026-07-01
 * v1.3 | 2026-07-21 — Remove Make.com settings tab and Post Framing card (deprecated,
 *                      unused on all sites).
 */

defined( 'ABSPATH' ) || exit;

use SiteEssentials\Modules\SocialAmplification\Post_Type_Config;
use SiteEssentials\Modules\SocialAmplification\SocialAmplification_Module as SMA;

// SCOS-SA-PASS1 — option reader: existing keys preserved; scos_sa_ prefix is target migration state.
// TODO: migrate key to scos_sa_ prefix — SCOS-SA-PASS1
$yourls_url  = SMA::get_option( 'scos_sma_yourls_url',       'bw_yourls_api_url' );
$yourls_sig  = SMA::get_option( 'scos_sma_yourls_signature', 'bw_yourls_signature' );
$yourls_user = SMA::get_option( 'scos_sma_yourls_username',  'bw_yourls_username' );
$yourls_pass = SMA::get_option( 'scos_sma_yourls_password',  'bw_yourls_password' );

// TODO: migrate key to scos_sa_ prefix — SCOS-SA-PASS1
$postly_api_key        = get_option( 'bw_postly_api_key', '' );
$postly_workspace_id   = get_option( 'bw_postly_workspace_id', '' );
$postly_channel_ids    = get_option( 'bw_postly_channel_ids', '' );
$acf_gallery_keys      = get_option( 'bw_social_acf_gallery_keys', '' );
$acf_featured_key      = get_option( 'bw_social_acf_featured_key', '' );
$webhook_secret        = get_option( 'bw_social_webhook_secret', '' );
$social_enabled        = get_option( 'bw_social_enabled', '' );
$publish_time_min      = get_option( 'bw_social_publish_time_min', '09:00' );
$publish_time_max      = get_option( 'bw_social_publish_time_max', '17:00' );

// New scos_sa_* scheduling fields
$postly_post_count    = get_option( 'scos_sa_postly_post_count', 3 );
$backfill_date_from   = get_option( 'scos_sa_backfill_date_from', '' );
$backfill_date_to     = get_option( 'scos_sa_backfill_date_to', '' );
$backfill_limit       = get_option( 'scos_sa_backfill_limit', 5 );
$backfill_slot_gap    = get_option( 'scos_sa_backfill_slot_gap_days', 0 );

// Per-channel fields
$fb_channel_id    = get_option( 'scos_sa_postly_fb_channel_id', '' );
$fb_enabled       = get_option( 'scos_sa_postly_fb_enabled', '1' );
$ig_channel_id    = get_option( 'scos_sa_postly_ig_channel_id', '' );
$ig_enabled       = get_option( 'scos_sa_postly_ig_enabled', '1' );
$gmb_channel_id   = get_option( 'scos_sa_postly_gmb_channel_id', get_option( 'se_postly_gmb_channel_id', '' ) );

// Global posting settings
$global_frames      = Post_Type_Config::get_global_frames();
$global_max_images  = get_option( Post_Type_Config::MAX_IMAGES_OPTION, Post_Type_Config::DEFAULT_MAX_IMAGES );
$global_no_featured = get_option( Post_Type_Config::NO_FEATURED_OPTION, 0 );
$global_no_attach   = get_option( Post_Type_Config::NO_ATTACH_OPTION, 0 );

// Per post type config
$pt_all_configs   = Post_Type_Config::get_all_configs();
$registered_types = Post_Type_Config::get_all_registered();

$page_url = admin_url( 'admin.php?page=site-essentials-social-amplification' );
?>

<?php // SCOS-SA-PASS1 — page chrome: canonical header + scos__tabs nav ?>

<?php if ( isset( $_GET['scos_sma_saved'] ) ) : ?>
  <div class="scos-notice scos-notice--success" style="margin-bottom:var(--scos-s-5)">
    <?php esc_html_e( 'Settings saved.', 'site-essentials' ); ?>
  </div>
<?php endif; ?>

<header class="scos__header">
  <div>
    <h1 class="scos__title"><?php esc_html_e( 'Social Amplification', 'site-essentials' ); ?></h1>
    <p class="scos__subtitle">Site Essentials &rsaquo; Social Amplification</p>
  </div>
  <div class="scos__header-actions">
    <button type="submit" id="scos-sa-header-save" form="scos-sa-form-yourls" class="scos-btn scos-btn--primary">
      <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
    </button>
  </div>
</header>

<nav class="scos__tabs" id="scos-sa-tabs">
  <a href="#settings" class="scos__tab" data-tab="settings"><?php esc_html_e( 'Settings', 'site-essentials' ); ?></a>
  <a href="#yourls"   class="scos__tab" data-tab="yourls"><?php esc_html_e( 'Short link – YOURLS', 'site-essentials' ); ?></a>
  <a href="#postly"   class="scos__tab" data-tab="postly"><?php esc_html_e( 'Postly.ai API settings', 'site-essentials' ); ?></a>
  <a href="#posting"  class="scos__tab" data-tab="posting"><?php esc_html_e( 'Posting Settings', 'site-essentials' ); ?></a>
</nav>

<?php // SCOS-SA-PASS2 — hash-based tab switcher updated for 'posting' tab ?>
<script>
(function() {
  var TABS     = ['settings','yourls','postly','posting'];
  var FORMS    = { yourls: 'scos-sa-form-yourls', postly: 'scos-sa-form-postly', posting: 'scos-sa-form-posting' };
  var panels   = {};
  var links    = {};
  var saveBtn;

  function activate(tab) {
    if ( TABS.indexOf(tab) === -1 ) tab = TABS[0];
    TABS.forEach(function(t) {
      if (panels[t]) panels[t].style.display = (t === tab) ? '' : 'none';
      if (links[t])  links[t].classList.toggle('scos__tab--active', t === tab);
    });
    // Update header Save button: show only when the active tab has a form
    if (saveBtn) {
      var formId = FORMS[tab];
      if (formId) {
        saveBtn.setAttribute('form', formId);
        saveBtn.style.display = '';
      } else {
        saveBtn.style.display = 'none';
      }
    }
    if (history.replaceState) history.replaceState(null, '', '#' + tab);
  }

  document.addEventListener('DOMContentLoaded', function() {
    saveBtn = document.getElementById('scos-sa-header-save');
    TABS.forEach(function(t) {
      panels[t] = document.getElementById('scos-sa-panel-' + t);
      links[t]  = document.querySelector('[data-tab="' + t + '"]');
      if (links[t]) {
        links[t].addEventListener('click', function(e) {
          e.preventDefault();
          activate(t);
        });
      }
    });

    // On load: check hash first, then ?scos_sma_tab= query param
    var hash    = window.location.hash.replace('#','');
    var params  = new URLSearchParams(window.location.search);
    var initial = TABS.indexOf(hash) !== -1 ? hash : ( params.get('scos_sma_tab') || TABS[0] );
    activate(initial);
  });
})();
</script>

<?php // ──────────────────────────────────────────────────────────────────────────── ?>
<?php // Tab 1 — Settings                                                            ?>
<?php // SCOS-SA-PASS1 — new tab; CPT buttons + guide tiles                         ?>
<?php // ──────────────────────────────────────────────────────────────────────────── ?>

<div id="scos-sa-panel-settings">

  <div class="scos-card">
    <div class="scos-card__header">
      <h3 class="scos-card__title"><?php esc_html_e( 'Social Amplification setup guide', 'site-essentials' ); ?></h3>
      <p class="scos-card__desc">
        <?php esc_html_e( 'Create, schedule, and automate social content using your website\'s existing content as inspiration and context. Uses on-site text and images for automated post creation — publish in one click direct from your website, or set up a review gate before anything goes live.', 'site-essentials' ); ?>
      </p>
    </div>
    <div class="scos-card__body">
      <div class="scos-support__grid">

        <a href="https://brighterwebsites.com.au/software/social-amplification/postly-ai-integration/"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong><?php esc_html_e( 'Social automation via Postly.ai', 'site-essentials' ); ?></strong>
          <span><?php esc_html_e( 'Schedule and automate posts using the Postly.ai API.', 'site-essentials' ); ?></span>
        </a>

        <a href="https://brighterwebsites.com.au/software/social-amplification/yourls-shortlink-integration/"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong><?php esc_html_e( 'Short links & UTM tracking via YOURLS', 'site-essentials' ); ?></strong>
          <span><?php esc_html_e( 'Auto-generate branded short links with UTM parameters on post creation.', 'site-essentials' ); ?></span>
        </a>

        <a href="https://brighterwebsites.com.au/software/social-amplification/postly-ai-integration/#ai-knowledge"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong><?php esc_html_e( 'AI content generation setup', 'site-essentials' ); ?></strong>
          <span><?php esc_html_e( 'Configure Claude API and AI knowledge documents for brand-voice content.', 'site-essentials' ); ?></span>
        </a>

      </div>
    </div>
  </div>

</div><!-- /#scos-sa-panel-settings -->

<?php // ──────────────────────────────────────────────────────────────────────────── ?>
<?php // Tab 2 — YOURLS                                                              ?>
<?php // SCOS-SA-PASS1 — scos-card, scos-form; existing keys preserved              ?>
<?php // ──────────────────────────────────────────────────────────────────────────── ?>

<div id="scos-sa-panel-yourls">

  <form id="scos-sa-form-yourls" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'scos_sma_save', 'scos_sma_nonce' ); ?>
    <input type="hidden" name="action" value="site_essentials_save_sma">
    <input type="hidden" name="_scos_sma_tab" value="yourls">

    <div class="scos-card">
      <div class="scos-card__header">
        <h3 class="scos-card__title"><?php esc_html_e( 'Shortlink integration – YOURLS', 'site-essentials' ); ?></h3>
        <a href="https://brighterwebsites.com.au/software/social-amplification/yourls-shortlink-integration/"
           target="_blank" rel="noopener" class="scos-badge scos-badge--soft"><?php esc_html_e( 'Guide', 'site-essentials' ); ?></a>
      </div>
      <div class="scos-card__body">

        <p class="description" style="margin-bottom:var(--scos-s-4)">
          <?php esc_html_e( 'Configure your self-hosted YOURLS installation. The shortlink slug entered on each post page is used as the YOURLS keyword to create your shortlink. UTM parameters are automatically attached to the redirected URL.', 'site-essentials' ); ?>
        </p>

        <div class="scos-notice scos-notice--info" style="margin-bottom:var(--scos-s-5)">
          <strong class="scos-notice__title"><?php esc_html_e( 'Example redirection', 'site-essentials' ); ?></strong>
          <code>shrtlnk.com/mypage</code> &rarr;
          <code>mydomainname.com.au/my-long-page-title?utm_source=social_media&amp;utm_medium=social&amp;utm_content=[page-type]&amp;utm_campaign=none</code>
        </div>

        <?php // SCOS-SA-PASS1 — input name uses scos_sma_yourls_url (existing key); slug label shows target scos_sa_ key ?>
        <?php // TODO: migrate key scos_sma_yourls_url → scos_sa_yourls_api_url — SCOS-SA-PASS1 ?>
        <table class="scos-form">
          <tbody>
            <tr>
              <th>
                <label for="scos_sma_yourls_url"><?php esc_html_e( 'YOURLS API URL', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_yourls_api_url</div>
              </th>
              <td>
                <input id="scos_sma_yourls_url" name="scos_sma_yourls_url" type="url"
                       class="scos-input" placeholder="https://yourdomain.com/yourls-api.php"
                       value="<?php echo esc_attr( $yourls_url ); ?>">
                <p class="description"><?php esc_html_e( 'Full path to yourls-api.php on your YOURLS installation.', 'site-essentials' ); ?></p>
              </td>
            </tr>
            <?php // TODO: migrate key scos_sma_yourls_signature → scos_sa_yourls_token — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label for="scos_sma_yourls_signature"><?php esc_html_e( 'Signature token', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_yourls_token</div>
              </th>
              <td>
                <input id="scos_sma_yourls_signature" name="scos_sma_yourls_signature" type="text"
                       class="scos-input scos-input--mono"
                       value="<?php echo esc_attr( $yourls_sig ); ?>">
                <p class="description">
                  <strong><?php esc_html_e( 'Recommended.', 'site-essentials' ); ?></strong>
                  <?php esc_html_e( 'Found in YOURLS Admin &rarr; Tools &rarr; Signature Token.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>
          </tbody>
        </table>

        <hr style="border:none;border-top:1px solid var(--scos-border);margin:var(--scos-s-5) 0">

        <table class="scos-form">
          <tbody>
            <tr>
              <th>
                <label for="scos_sma_yourls_username"><?php esc_html_e( 'Username', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_yourls_username</div>
              </th>
              <td>
                <input id="scos_sma_yourls_username" name="scos_sma_yourls_username" type="text"
                       class="scos-input"
                       value="<?php echo esc_attr( $yourls_user ); ?>">
                <p class="description"><?php esc_html_e( 'Only needed if not using a signature token.', 'site-essentials' ); ?></p>
              </td>
            </tr>
            <tr>
              <th>
                <label for="scos_sma_yourls_password"><?php esc_html_e( 'Password', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_yourls_password</div>
              </th>
              <td>
                <input id="scos_sma_yourls_password" name="scos_sma_yourls_password" type="password"
                       class="scos-input"
                       value="<?php echo esc_attr( $yourls_pass ); ?>">
                <p class="description"><?php esc_html_e( 'Only needed if not using a signature token.', 'site-essentials' ); ?></p>
              </td>
            </tr>
          </tbody>
        </table>

      </div>
      <div class="scos-card__footer">
        <button type="submit" class="scos-btn scos-btn--primary">
          <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
        </button>
      </div>
    </div><!-- /.scos-card -->

  </form>

</div><!-- /#scos-sa-panel-yourls -->

<?php // ──────────────────────────────────────────────────────────────────────────── ?>
<?php // Tab 3 — Postly.ai API settings                                              ?>
<?php // SCOS-SA-PASS1 — 3 cards: Scheduling, Postly API, AI knowledge docs         ?>
<?php // ──────────────────────────────────────────────────────────────────────────── ?>

<div id="scos-sa-panel-postly">

  <form id="scos-sa-form-postly" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'scos_sma_save', 'scos_sma_nonce' ); ?>
    <input type="hidden" name="action" value="site_essentials_save_sma">
    <input type="hidden" name="_scos_sma_tab" value="postly">

    <?php // ── Card 1: Scheduling ──────────────────────────────────────────────── ?>

    <div class="scos-card">
      <div class="scos-card__header">
        <h3 class="scos-card__title"><?php esc_html_e( 'Scheduling', 'site-essentials' ); ?></h3>
        <a href="https://brighterwebsites.com.au/software/social-amplification/postly-ai-integration/"
           target="_blank" rel="noopener" class="scos-badge scos-badge--soft"><?php esc_html_e( 'Guide', 'site-essentials' ); ?></a>
      </div>
      <div class="scos-card__body">

        <p class="scos__section-label"><?php esc_html_e( 'New content', 'site-essentials' ); ?></p>

        <table class="scos-form">
          <tbody>

            <?php // TODO: migrate key bw_social_enabled → scos_sa_postly_enabled — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label for="bw_social_enabled"><?php esc_html_e( 'Enable social amplification', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_enabled</div>
              </th>
              <td>
                <label class="scos-checkbox-row">
                  <input id="bw_social_enabled" name="bw_social_enabled" type="checkbox"
                         value="1" <?php checked( $social_enabled, '1' ); ?>>
                  <?php esc_html_e( 'Automatically amplify when a Projects post is published', 'site-essentials' ); ?>
                </label>
                <p class="description">
                  <?php esc_html_e( 'Automatically amplifies any published post where "Social Amplification" is checked in the Optimization Progress field, and amplification has not yet run. Requires Postly API key, Workspace ID, and Anthropic API key (in', 'site-essentials' ); ?>
                  <a href="<?php echo esc_url( admin_url( 'admin.php?page=site-essentials-settings&tab=ai-keys' ) ); ?>"><?php esc_html_e( 'Settings &rarr; AI API Keys', 'site-essentials' ); ?></a>)
                  <?php esc_html_e( 'to be configured.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>

            <?php // TODO: migrate keys bw_social_publish_time_min/max → scos_sa_postly_window_from/to — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label><?php esc_html_e( 'Publish time window', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_window_from / _to</div>
              </th>
              <td>
                <div style="display:flex;align-items:center;gap:var(--scos-s-2)">
                  <span><?php esc_html_e( 'From', 'site-essentials' ); ?></span>
                  <input id="bw_social_publish_time_min" name="bw_social_publish_time_min"
                         type="time" class="scos-input" style="width:130px"
                         value="<?php echo esc_attr( $publish_time_min ); ?>">
                  <span><?php esc_html_e( 'To', 'site-essentials' ); ?></span>
                  <input id="bw_social_publish_time_max" name="bw_social_publish_time_max"
                         type="time" class="scos-input" style="width:130px"
                         value="<?php echo esc_attr( $publish_time_max ); ?>">
                </div>
                <p class="description">
                  <?php esc_html_e( 'Posts are scheduled at a random time within this window (site timezone). Slot 1 is always pushed at least 60 minutes into the future to allow for Postly processing and approval.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>

            <tr>
              <th>
                <label for="scos_sa_postly_post_count"><?php esc_html_e( 'Posts to create', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_post_count</div>
              </th>
              <td>
                <input id="scos_sa_postly_post_count" name="scos_sa_postly_post_count"
                       type="number" class="scos-input" style="width:80px" min="1" max="10"
                       value="<?php echo esc_attr( $postly_post_count ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Number of social posts generated per project publish. GMB always creates 1 post regardless of this setting.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>

          </tbody>
        </table>

        <hr style="border:none;border-top:1px solid var(--scos-border);margin:var(--scos-s-5) 0">

        <p class="scos__section-label"><?php esc_html_e( 'Existing content backfill', 'site-essentials' ); ?></p>

        <?php
        // SCOS-SA-PASS1 — backfill data attributes preserved so backfill.js continues to work.
        $bw_secret = get_option( 'bw_social_webhook_secret', '' );
        ?>
        <div id="scos-sa-backfill-wrap"
             data-secret="<?php echo esc_attr( $bw_secret ); ?>"
             data-rest="<?php echo esc_attr( rest_url( 'bw-social/v1/backfill' ) ); ?>"
             data-slot-gap-field="scos_sa_backfill_slot_gap_days">

          <table class="scos-form">
            <tbody>
              <tr>
                <th>
                  <label><?php esc_html_e( 'Date range', 'site-essentials' ); ?></label>
                  <div class="scos-form__slug">scos_sa_backfill_date_from / _to</div>
                </th>
                <td>
                  <div style="display:flex;align-items:center;gap:var(--scos-s-2)">
                    <span><?php esc_html_e( 'From', 'site-essentials' ); ?></span>
                    <input name="scos_sa_backfill_date_from" id="scos_sa_backfill_from"
                           type="date" class="scos-input" style="width:160px"
                           value="<?php echo esc_attr( $backfill_date_from ?: gmdate( 'Y-m-01' ) ); ?>">
                    <span><?php esc_html_e( 'To', 'site-essentials' ); ?></span>
                    <input name="scos_sa_backfill_date_to" id="scos_sa_backfill_to"
                           type="date" class="scos-input" style="width:160px"
                           value="<?php echo esc_attr( $backfill_date_to ?: gmdate( 'Y-m-d' ) ); ?>">
                  </div>
                  <p class="description"><?php esc_html_e( 'Run social amplification for existing project posts published within this date range.', 'site-essentials' ); ?></p>
                </td>
              </tr>
              <tr>
                <th>
                  <label for="scos_sa_backfill_limit"><?php esc_html_e( 'Limit', 'site-essentials' ); ?></label>
                  <div class="scos-form__slug">scos_sa_backfill_limit</div>
                </th>
                <td>
                  <input id="scos_sa_backfill_limit" name="scos_sa_backfill_limit"
                         type="number" class="scos-input" style="width:80px" min="1"
                         value="<?php echo esc_attr( $backfill_limit ); ?>">
                  <p class="description"><?php esc_html_e( 'Maximum number of posts to process in this backfill run.', 'site-essentials' ); ?></p>
                </td>
              </tr>
              <tr>
                <th>
                  <label for="scos_sa_backfill_slot_gap_days"><?php esc_html_e( 'Slot gap (days)', 'site-essentials' ); ?></label>
                  <div class="scos-form__slug">scos_sa_backfill_slot_gap_days</div>
                </th>
                <td>
                  <input id="scos_sa_backfill_slot_gap_days" name="scos_sa_backfill_slot_gap_days"
                         type="number" class="scos-input" style="width:80px" min="0" max="365"
                         value="<?php echo esc_attr( $backfill_slot_gap ); ?>">
                  <p class="description">
                    <?php esc_html_e( 'Minimum calendar days between consecutive slots in this backfill run. 0 = pack into the next available Mon/Wed/Fri slots. 7 = space slots at least 1 week apart.', 'site-essentials' ); ?>
                  </p>
                </td>
              </tr>
            </tbody>
          </table>

          <div id="scos-sa-backfill-status" class="scos-sa-result" hidden></div>
          <div id="scos-sa-backfill-results"></div>

        </div><!-- /#scos-sa-backfill-wrap -->

      </div>
      <div class="scos-card__footer" style="display:flex;justify-content:space-between;align-items:center">
        <button type="button" id="scos-sa-run-backfill" class="scos-btn">
          <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Run backfill', 'site-essentials' ); ?>
        </button>
        <button type="submit" class="scos-btn scos-btn--primary">
          <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
        </button>
      </div>
    </div><!-- /.scos-card Scheduling -->

    <?php // ── Card 2: Postly API – integration ──────────────────────────────── ?>

    <div class="scos-card">
      <div class="scos-card__header">
        <h3 class="scos-card__title"><?php esc_html_e( 'Postly API – integration', 'site-essentials' ); ?></h3>
      </div>
      <div class="scos-card__body">

        <p class="scos__section-label"><?php esc_html_e( 'Workspace settings', 'site-essentials' ); ?></p>

        <table class="scos-form">
          <tbody>
            <?php // TODO: migrate key bw_postly_api_key → scos_sa_postly_api_key — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label for="bw_postly_api_key"><?php esc_html_e( 'Postly API key', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_api_key</div>
              </th>
              <td>
                <?php // Rendered empty and never populated: the stored key must not reach the page source. ?>
                <input id="bw_postly_api_key" name="bw_postly_api_key" type="password"
                       class="scos-input scos-input--mono"
                       autocomplete="new-password"
                       value=""
                       placeholder="<?php echo esc_attr( $postly_api_key ? __( 'Saved — leave blank to keep', 'site-essentials' ) : '' ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Your Postly.ai API key. Get it from', 'site-essentials' ); ?>
                  <a href="https://app.postly.ai" target="_blank" rel="noopener">app.postly.ai</a>.
                </p>
                <?php if ( $postly_api_key ) : ?>
                  <label class="description" for="bw_postly_api_key_clear">
                    <input type="checkbox" id="bw_postly_api_key_clear" name="bw_postly_api_key_clear" value="1">
                    <?php esc_html_e( 'Delete the stored key', 'site-essentials' ); ?>
                  </label>
                <?php endif; ?>
              </td>
            </tr>
            <?php // TODO: migrate key bw_postly_workspace_id → scos_sa_postly_workspace_id — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label for="bw_postly_workspace_id"><?php esc_html_e( 'Postly workspace ID', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_workspace_id</div>
              </th>
              <td>
                <input id="bw_postly_workspace_id" name="bw_postly_workspace_id" type="text"
                       class="scos-input scos-input--mono"
                       value="<?php echo esc_attr( $postly_workspace_id ); ?>">
                <p class="description"><?php esc_html_e( 'Found in the Workspace settings URL in your Postly account.', 'site-essentials' ); ?></p>
              </td>
            </tr>
          </tbody>
        </table>

        <?php // ── Fetch channel IDs helper ──────────────────────────────── ?>
        <div style="display:flex;align-items:center;gap:var(--scos-s-3);margin-top:var(--scos-s-4)">
          <button type="button" id="scos-sa-fetch-channels" class="scos-btn"
                  data-nonce="<?php echo esc_attr( wp_create_nonce( 'scos_sa_channels' ) ); ?>"
                  data-ajaxurl="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>">
            <span class="dashicons dashicons-update" style="margin-right:4px"></span>
            <?php esc_html_e( 'Fetch channel IDs', 'site-essentials' ); ?>
          </button>
          <span id="scos-sa-channels-msg" style="font-size:var(--scos-fs-sm);color:var(--scos-ink-subtle)"></span>
        </div>
        <div id="scos-sa-channels-result" style="margin-top:var(--scos-s-3)"></div>

        <hr style="border:none;border-top:1px solid var(--scos-border);margin:var(--scos-s-5) 0">

        <p class="scos__section-label"><?php esc_html_e( 'Social channel settings', 'site-essentials' ); ?></p>

        <p class="description" style="margin-bottom:var(--scos-s-4)">
          <?php esc_html_e( 'Configure each platform separately. Use "Fetch channel IDs" above to find the IDs. Leave a channel ID blank to exclude that platform. Toggle enables/disables without clearing the ID.', 'site-essentials' ); ?>
          <?php esc_html_e( 'Migrate existing Facebook/Instagram IDs from the legacy field below to their dedicated fields.', 'site-essentials' ); ?>
        </p>

        <table class="scos-form">
          <tbody>

            <tr>
              <th>
                <label for="scos_sa_postly_fb_channel_id"><?php esc_html_e( 'Facebook channel ID', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_fb_channel_id</div>
              </th>
              <td>
                <div style="display:flex;align-items:center;gap:var(--scos-s-3)">
                  <label class="scos-checkbox-row" style="margin:0">
                    <input type="checkbox" name="scos_sa_postly_fb_enabled" value="1"
                           <?php checked( $fb_enabled, '1' ); ?>>
                    <?php esc_html_e( 'Enabled', 'site-essentials' ); ?>
                  </label>
                  <input id="scos_sa_postly_fb_channel_id" name="scos_sa_postly_fb_channel_id"
                         type="text" class="scos-input scos-input--mono" style="flex:1"
                         placeholder="ch_xxxxxxxxxxxxxxxxxx"
                         value="<?php echo esc_attr( $fb_channel_id ); ?>">
                </div>
              </td>
            </tr>

            <tr>
              <th>
                <label for="scos_sa_postly_ig_channel_id"><?php esc_html_e( 'Instagram channel ID', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_ig_channel_id</div>
              </th>
              <td>
                <div style="display:flex;align-items:center;gap:var(--scos-s-3)">
                  <label class="scos-checkbox-row" style="margin:0">
                    <input type="checkbox" name="scos_sa_postly_ig_enabled" value="1"
                           <?php checked( $ig_enabled, '1' ); ?>>
                    <?php esc_html_e( 'Enabled', 'site-essentials' ); ?>
                  </label>
                  <input id="scos_sa_postly_ig_channel_id" name="scos_sa_postly_ig_channel_id"
                         type="text" class="scos-input scos-input--mono" style="flex:1"
                         placeholder="ch_xxxxxxxxxxxxxxxxxx"
                         value="<?php echo esc_attr( $ig_channel_id ); ?>">
                </div>
              </td>
            </tr>

            <tr>
              <th>
                <label for="scos_sa_postly_gmb_channel_id"><?php esc_html_e( 'GMB channel ID', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_gmb_channel_id</div>
              </th>
              <td>
                <input id="scos_sa_postly_gmb_channel_id" name="scos_sa_postly_gmb_channel_id"
                       type="text" class="scos-input scos-input--mono"
                       placeholder="ch_xxxxxxxxxxxxxxxxxx"
                       value="<?php echo esc_attr( $gmb_channel_id ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Google Business Profile channel. Scheduled to Tue/Thu slots. Only one GMB profile per workspace is supported.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>

            <tr>
              <th>
                <label for="bw_postly_channel_ids"><?php esc_html_e( 'Others (legacy / additional)', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_channel_ids</div>
              </th>
              <td>
                <input id="bw_postly_channel_ids" name="bw_postly_channel_ids" type="text"
                       class="scos-input scos-input--mono" placeholder="id1, id2, id3"
                       value="<?php echo esc_attr( $postly_channel_ids ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Comma-separated Postly channel IDs for Pinterest and any other platforms. If you previously entered Facebook/Instagram IDs here, move them to the dedicated fields above.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>

          </tbody>
        </table>

        <hr style="border:none;border-top:1px solid var(--scos-border);margin:var(--scos-s-5) 0">

        <p class="scos__section-label"><?php esc_html_e( 'ACF field settings', 'site-essentials' ); ?></p>

        <table class="scos-form">
          <tbody>
            <?php // TODO: migrate key bw_social_acf_gallery_keys → scos_sa_postly_acf_gallery_keys — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label for="bw_social_acf_gallery_keys"><?php esc_html_e( 'ACF gallery field keys', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_acf_gallery_keys</div>
              </th>
              <td>
                <input id="bw_social_acf_gallery_keys" name="bw_social_acf_gallery_keys"
                       type="text" class="scos-input scos-input--mono"
                       placeholder="project_gallery, secondary_gallery"
                       value="<?php echo esc_attr( $acf_gallery_keys ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Comma-separated ACF field keys containing gallery images. Combined with the featured image for post image sets.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>
            <?php // TODO: migrate key bw_social_acf_featured_key → scos_sa_postly_acf_hero_key — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label for="bw_social_acf_featured_key"><?php esc_html_e( 'ACF featured image key', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_acf_hero_key</div>
              </th>
              <td>
                <input id="bw_social_acf_featured_key" name="bw_social_acf_featured_key"
                       type="text" class="scos-input scos-input--mono"
                       value="<?php echo esc_attr( $acf_featured_key ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Optional. Overrides the standard WordPress featured image as the first image. Leave blank to use the WP featured image.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>
            <?php // TODO: migrate key bw_social_webhook_secret → scos_sa_postly_webhook_secret — SCOS-SA-PASS1 ?>
            <tr>
              <th>
                <label><?php esc_html_e( 'Webhook secret', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_webhook_secret</div>
              </th>
              <td>
                <?php if ( $webhook_secret ) : ?>
                  <code class="scos-input--mono" style="font-size:var(--scos-fs-xs)"><?php echo esc_html( $webhook_secret ); ?></code>
                <?php else : ?>
                  <p style="color:var(--scos-warning);font-weight:500"><?php esc_html_e( 'Not yet generated — save settings to auto-generate.', 'site-essentials' ); ?></p>
                <?php endif; ?>
                <p class="description">
                  <?php esc_html_e( 'Auto-generated. Authenticates the internal REST endpoint (POST /wp-json/bw-social/v1/amplify). Keep private.', 'site-essentials' ); ?>
                </p>
                <?php
                // No hidden field round-tripping the secret through the browser. The save
                // handler generates one when absent and otherwise only writes when the
                // posted value differs from the stored one — and a field pre-filled with
                // the stored value never differs, so it could never do anything except
                // put the secret in the page source on every load.
                ?>
              </td>
            </tr>
          </tbody>
        </table>

      </div>
      <div class="scos-card__footer">
        <button type="submit" class="scos-btn scos-btn--primary">
          <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
        </button>
      </div>
    </div><!-- /.scos-card Postly API -->

  </form>

  <?php // ── Card 3: AI knowledge documents (read-only, outside form) ─────── ?>

  <div class="scos-card">
    <div class="scos-card__header">
      <h3 class="scos-card__title"><?php esc_html_e( 'AI knowledge documents', 'site-essentials' ); ?></h3>
      <a href="https://brighterwebsites.com.au/software/social-amplification/postly-ai-integration/#ai-knowledge"
         target="_blank" rel="noopener" class="scos-badge scos-badge--soft"><?php esc_html_e( 'Guide', 'site-essentials' ); ?></a>
    </div>
    <div class="scos-card__body">

      <p class="description" style="margin-bottom:var(--scos-s-4)">
        <?php esc_html_e( 'These markdown files are read by the AI during post generation. Edit them to control brand voice, vocabulary, and per-channel posting rules. Located at', 'site-essentials' ); ?>
        <code>/wp-content/ai-knowledge/</code>
      </p>

      <div class="scos-support__grid">

        <a href="<?php echo esc_url( content_url( 'ai-knowledge/301-social-brand-voice.md' ) ); ?>"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong>301-social-brand-voice.md</strong>
          <span><?php esc_html_e( 'Brand voice for social media — how your posts should sound, tone, style, and personality.', 'site-essentials' ); ?></span>
        </a>

        <a href="<?php echo esc_url( content_url( 'ai-knowledge/302-social-media-meta.md' ) ); ?>"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong>302-social-media-meta.md</strong>
          <span><?php esc_html_e( 'Rules for Facebook & Instagram posts — word length, hashtag and emoji usage, link inclusion.', 'site-essentials' ); ?></span>
        </a>

        <a href="<?php echo esc_url( content_url( 'ai-knowledge/303-social-media-gmb.md' ) ); ?>"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong>303-social-media-gmb.md</strong>
          <span><?php esc_html_e( 'Rules for Google Business posts — ensures GMB terms of use compliance (no contact details or URLs in body).', 'site-essentials' ); ?></span>
        </a>

        <a href="<?php echo esc_url( content_url( 'ai-knowledge/304-brand-vocabulary.md' ) ); ?>"
           target="_blank" rel="noopener" class="scos-support__tile">
          <strong>304-brand-vocabulary.md</strong>
          <span><?php esc_html_e( 'Words and phrases to use and never use in generated content.', 'site-essentials' ); ?></span>
        </a>

      </div>

    </div>
  </div><!-- /.scos-card AI knowledge docs -->

<?php // ── Postly channel fetch JS ──────────────────────────────────────────── ?>
<script>
(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var btn     = document.getElementById('scos-sa-fetch-channels');
    var result  = document.getElementById('scos-sa-channels-result');
    var msg     = document.getElementById('scos-sa-channels-msg');

    if ( ! btn ) return;

    btn.addEventListener('click', function() {
      msg.textContent  = '<?php echo esc_js( __( 'Fetching…', 'site-essentials' ) ); ?>';
      result.innerHTML = '';
      btn.disabled     = true;

      var data = new FormData();
      data.append('action', 'scos_sa_fetch_postly_channels');
      data.append('nonce',  btn.dataset.nonce);

      fetch(btn.dataset.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(json) {
          btn.disabled = false;
          if ( ! json.success ) {
            msg.textContent = '';
            result.innerHTML = '<div class="scos-notice scos-notice--danger">' + escHtml(json.data.message || '<?php echo esc_js( __( 'Request failed.', 'site-essentials' ) ); ?>') + '</div>';
            return;
          }

          var channels = json.data.channels;
          msg.textContent = '';

          if ( ! channels || channels.length === 0 ) {
            result.innerHTML = '<div class="scos-notice scos-notice--info"><?php echo esc_js( __( 'No connected channels found for this workspace.', 'site-essentials' ) ); ?></div>';
            return;
          }

          var rows = channels.map(function(ch) {
            var target = ch.target.charAt(0).toUpperCase() + ch.target.slice(1);
            return '<tr>'
              + '<td style="padding:6px 12px 6px 0;font-weight:500;white-space:nowrap">' + escHtml(target) + '</td>'
              + '<td style="padding:6px 12px 6px 0;color:var(--scos-ink-subtle)">' + escHtml(ch.name) + '</td>'
              + '<td style="padding:6px 12px 6px 0"><code class="scos-input--mono" style="font-size:var(--scos-fs-sm);background:var(--scos-surface-2);padding:2px 6px;border-radius:var(--scos-r-sm)">' + escHtml(ch.channel_id) + '</code></td>'
              + '<td style="padding:6px 0"><button type="button" class="scos-btn" style="padding:2px 10px;font-size:var(--scos-fs-sm)" onclick="scosCopyId(this,\'' + escHtml(ch.channel_id) + '\')"><?php echo esc_js( __( 'Copy ID', 'site-essentials' ) ); ?></button></td>'
              + '</tr>';
          }).join('');

          result.innerHTML = '<table style="border-collapse:collapse;width:100%"><tbody>' + rows + '</tbody></table>';
        })
        .catch(function() {
          btn.disabled    = false;
          msg.textContent = '';
          result.innerHTML = '<div class="scos-notice scos-notice--danger"><?php echo esc_js( __( 'Network error — check your connection and try again.', 'site-essentials' ) ); ?></div>';
        });
    });
  });

  function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
  }

  window.scosCopyId = function(btn, id) {
    navigator.clipboard.writeText(id).then(function() {
      var orig = btn.textContent;
      btn.textContent = '<?php echo esc_js( __( 'Copied!', 'site-essentials' ) ); ?>';
      setTimeout(function() { btn.textContent = orig; }, 1800);
    });
  };
})();
</script>

</div><!-- /#scos-sa-panel-postly -->

<?php // ──────────────────────────────────────────────────────────────────────────── ?>
<?php // Tab 4 — Posting Settings (NEW)                                              ?>
<?php // SCOS-SA-PASS2 — global framing angles + per-post-type config accordion     ?>
<?php // ──────────────────────────────────────────────────────────────────────────── ?>

<div id="scos-sa-panel-posting">

  <form id="scos-sa-form-posting" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'scos_sma_save', 'scos_sma_nonce' ); ?>
    <input type="hidden" name="action" value="site_essentials_save_sma">
    <input type="hidden" name="_scos_sma_tab" value="posting">

    <?php // ── Card 1: Global defaults ──────────────────────────────────────── ?>

    <div class="scos-card">
      <div class="scos-card__header">
        <h3 class="scos-card__title"><?php esc_html_e( 'All posts — global defaults', 'site-essentials' ); ?></h3>
        <p class="scos-card__desc">
          <?php esc_html_e( 'Applied to all post types unless overridden by a per-post-type configuration below.', 'site-essentials' ); ?>
        </p>
      </div>
      <div class="scos-card__body">

        <p class="scos__section-label"><?php esc_html_e( 'How to frame social posts', 'site-essentials' ); ?></p>

        <p class="description" style="margin-bottom:var(--scos-s-4)">
          <?php esc_html_e( 'Each input is one framing angle injected into the AI prompt as post_1, post_2, post_3. When post count exceeds 3, angles cycle from the beginning.', 'site-essentials' ); ?>
        </p>

        <?php for ( $f = 0; $f < 3; $f++ ) : ?>
        <div style="margin-bottom:var(--scos-s-3)">
          <textarea name="<?php echo esc_attr( Post_Type_Config::FRAMES_OPTION ); ?>[<?php echo $f; ?>]"
                    rows="2" class="scos-input" style="width:100%"><?php echo esc_textarea( $global_frames[ $f ] ?? '' ); ?></textarea>
        </div>
        <?php endfor; ?>

        <hr style="border:none;border-top:1px solid var(--scos-border);margin:var(--scos-s-5) 0">

        <p class="scos__section-label"><?php esc_html_e( 'Image settings', 'site-essentials' ); ?></p>

        <table class="scos-form">
          <tbody>
            <tr>
              <th>
                <label><?php esc_html_e( 'Image sources', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_no_featured / _no_attachments</div>
              </th>
              <td>
                <label class="scos-checkbox-row">
                  <input type="checkbox" name="<?php echo esc_attr( Post_Type_Config::NO_FEATURED_OPTION ); ?>"
                         value="1" <?php checked( $global_no_featured, '1' ); ?>>
                  <?php esc_html_e( "Don't use featured image", 'site-essentials' ); ?>
                </label>
                <label class="scos-checkbox-row" style="margin-top:var(--scos-s-2)">
                  <input type="checkbox" name="<?php echo esc_attr( Post_Type_Config::NO_ATTACH_OPTION ); ?>"
                         value="1" <?php checked( $global_no_attach, '1' ); ?>>
                  <?php esc_html_e( "Don't use post image attachments", 'site-essentials' ); ?>
                </label>
                <p class="description" style="margin-top:var(--scos-s-2)">
                  <?php esc_html_e( 'Featured image and image attachments are used by default. Add ACF image or gallery field keys in Postly API settings → ACF field settings.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>
            <tr>
              <th>
                <label for="scos_sa_postly_max_images"><?php esc_html_e( 'Max images per social post', 'site-essentials' ); ?></label>
                <div class="scos-form__slug">scos_sa_postly_max_images</div>
              </th>
              <td>
                <input id="scos_sa_postly_max_images" name="<?php echo esc_attr( Post_Type_Config::MAX_IMAGES_OPTION ); ?>"
                       type="number" class="scos-input" style="width:80px" min="1" max="20"
                       value="<?php echo esc_attr( $global_max_images ); ?>">
                <p class="description">
                  <?php esc_html_e( 'Maximum number of images uploaded to Postly per individual social post slot. Default: 4.', 'site-essentials' ); ?>
                </p>
              </td>
            </tr>
          </tbody>
        </table>

      </div>
      <div class="scos-card__footer">
        <button type="submit" class="scos-btn scos-btn--primary">
          <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
        </button>
      </div>
    </div><!-- /.scos-card Global defaults -->

    <?php // ── Card 2: Per post type ────────────────────────────────────────── ?>

    <div class="scos-card">
      <div class="scos-card__header">
        <h3 class="scos-card__title"><?php esc_html_e( 'Configure social posts per post type', 'site-essentials' ); ?></h3>
        <p class="scos-card__desc">
          <?php esc_html_e( 'When per-post-type settings are enabled, they override all global defaults for that post type.', 'site-essentials' ); ?>
        </p>
      </div>
      <div class="scos-card__body" style="padding:0">

        <?php if ( empty( $registered_types ) ) : ?>
          <div class="scos-empty" style="padding:var(--scos-s-5)">
            <p class="scos-empty__desc"><?php esc_html_e( 'No publicly queryable post types found.', 'site-essentials' ); ?></p>
          </div>
        <?php else : ?>

          <?php foreach ( $registered_types as $pt_slug => $pt_label ) :
            $pt_cfg     = $pt_all_configs[ $pt_slug ] ?? [];
            $pt_enabled = ! empty( $pt_cfg['enabled'] );
            $pt_count   = (int) ( $pt_cfg['post_count'] ?? 3 );
            $pt_frames  = ( ! empty( $pt_cfg['frames'] ) && is_array( $pt_cfg['frames'] ) ) ? $pt_cfg['frames'] : $global_frames;
            // Pad frames array to 3 slots
            while ( count( $pt_frames ) < 3 ) {
              $pt_frames[] = '';
            }
            $row_id = 'scos-sa-pt-' . sanitize_html_class( $pt_slug );
          ?>
          <div class="scos-sa-pt-row" style="border-bottom:1px solid var(--scos-border)">

            <div class="scos-sa-pt-header" style="display:flex;align-items:center;gap:var(--scos-s-3);padding:var(--scos-s-4) var(--scos-s-5);cursor:pointer"
                 onclick="scosTogglePTRow('<?php echo esc_js( $row_id ); ?>')">
              <label class="scos-checkbox-row" style="margin:0" onclick="event.stopPropagation()">
                <input type="checkbox"
                       name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][enabled]"
                       value="1"
                       id="<?php echo esc_attr( $row_id ); ?>-enable"
                       <?php checked( $pt_enabled ); ?>
                       onchange="scosTogglePTRow('<?php echo esc_js( $row_id ); ?>', this.checked)">
              </label>
              <span style="font-weight:500;flex:1"><?php echo esc_html( $pt_label ); ?>
                <code style="font-size:var(--scos-fs-sm);font-weight:400;color:var(--scos-ink-subtle);margin-left:var(--scos-s-2)"><?php echo esc_html( $pt_slug ); ?></code>
              </span>
              <div style="display:flex;align-items:center;gap:var(--scos-s-2);color:var(--scos-ink-subtle);font-size:var(--scos-fs-sm)" onclick="event.stopPropagation()">
                <input type="number"
                       name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][post_count]"
                       class="scos-input" style="width:60px;font-size:var(--scos-fs-sm)"
                       min="1" max="10" value="<?php echo esc_attr( $pt_count ); ?>"
                       aria-label="<?php esc_attr_e( 'Number of social posts', 'site-essentials' ); ?>">
                <span><?php esc_html_e( 'posts to create', 'site-essentials' ); ?></span>
              </div>
              <span class="dashicons dashicons-arrow-down-alt2" style="color:var(--scos-ink-subtle)"></span>
            </div>

            <div id="<?php echo esc_attr( $row_id ); ?>-panel"
                 style="padding:var(--scos-s-4) var(--scos-s-5) var(--scos-s-5);background:var(--scos-surface-2);<?php echo $pt_enabled ? '' : 'display:none'; ?>">

              <p class="scos__section-label" style="margin-bottom:var(--scos-s-3)"><?php esc_html_e( 'Images', 'site-essentials' ); ?></p>

              <label class="scos-checkbox-row">
                <input type="checkbox"
                       name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][no_featured]"
                       value="1" <?php checked( ! empty( $pt_cfg['no_featured'] ) ); ?>>
                <?php esc_html_e( "Don't use featured image", 'site-essentials' ); ?>
              </label>
              <label class="scos-checkbox-row" style="margin-top:var(--scos-s-2)">
                <input type="checkbox"
                       name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][no_attachments]"
                       value="1" <?php checked( ! empty( $pt_cfg['no_attachments'] ) ); ?>>
                <?php esc_html_e( "Don't use post image attachments", 'site-essentials' ); ?>
              </label>

              <div style="display:flex;align-items:center;gap:var(--scos-s-3);margin-top:var(--scos-s-3)">
                <label class="scos-checkbox-row" style="margin:0">
                  <input type="checkbox"
                         id="<?php echo esc_attr( $row_id ); ?>-use-acf"
                         onchange="document.getElementById('<?php echo esc_js( $row_id ); ?>-acf-wrap').style.display=this.checked?'':'none'"
                         <?php checked( ! empty( $pt_cfg['acf_gallery_keys'] ) ); ?>>
                  <?php esc_html_e( 'Use ACF gallery', 'site-essentials' ); ?>
                </label>
                <div id="<?php echo esc_attr( $row_id ); ?>-acf-wrap"
                     style="flex:1;<?php echo empty( $pt_cfg['acf_gallery_keys'] ) ? 'display:none' : ''; ?>">
                  <input type="text"
                         name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][acf_gallery_keys]"
                         class="scos-input scos-input--mono" style="width:100%"
                         placeholder="galleryfield1, galleryfield2"
                         value="<?php echo esc_attr( $pt_cfg['acf_gallery_keys'] ?? '' ); ?>">
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:var(--scos-s-3);margin-top:var(--scos-s-3)">
                <label style="font-size:var(--scos-fs-sm);color:var(--scos-ink-subtle)">
                  <?php esc_html_e( 'Max images per post', 'site-essentials' ); ?>
                </label>
                <input type="number"
                       name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][max_images]"
                       class="scos-input" style="width:70px"
                       min="1" max="20"
                       value="<?php echo esc_attr( $pt_cfg['max_images'] ?? $global_max_images ); ?>">
              </div>

              <p class="scos__section-label" style="margin-top:var(--scos-s-4);margin-bottom:var(--scos-s-3)"><?php esc_html_e( 'How to frame social posts', 'site-essentials' ); ?></p>

              <?php for ( $f = 0; $f < 3; $f++ ) : ?>
              <div style="margin-bottom:var(--scos-s-3)">
                <textarea name="scos_sa_pt_config[<?php echo esc_attr( $pt_slug ); ?>][frames][<?php echo $f; ?>]"
                          rows="2" class="scos-input" style="width:100%"><?php echo esc_textarea( $pt_frames[ $f ] ?? '' ); ?></textarea>
              </div>
              <?php endfor; ?>

            </div><!-- /.panel -->

          </div><!-- /.scos-sa-pt-row -->
          <?php endforeach; ?>

        <?php endif; ?>

      </div>
      <div class="scos-card__footer">
        <button type="submit" class="scos-btn scos-btn--primary">
          <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save changes', 'site-essentials' ); ?>
        </button>
      </div>
    </div><!-- /.scos-card Per post type -->

  </form>

</div><!-- /#scos-sa-panel-posting -->

<script>
(function() {
  function scosTogglePTRow(rowId, forceState) {
    var panel = document.getElementById(rowId + '-panel');
    if (!panel) return;
    var isOpen = panel.style.display !== 'none';
    var nextState = (forceState !== undefined) ? forceState : !isOpen;
    panel.style.display = nextState ? '' : 'none';
  }
  window.scosTogglePTRow = scosTogglePTRow;
})();
</script>

