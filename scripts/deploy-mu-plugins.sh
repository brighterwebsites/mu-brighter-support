#!/usr/bin/env bash
#
# deploy-mu-plugins.sh - Deploy SCOS MU plugins to Brighter Websites managed sites
# v2.1 | 2026-08-17
#
# Run as root on the web server. Fetches a specific commit of this repo from
# GitHub and syncs the folders/files this repo owns into each site's
# wp-content/mu-plugins directory.
#
# USAGE
#   ./deploy-mu-plugins.sh                        # deploy main to every enabled site
#   ./deploy-mu-plugins.sh feat/brighter-webmcp   # deploy a branch (testing)
#   ./deploy-mu-plugins.sh v1.4.0                 # deploy a tag
#   ./deploy-mu-plugins.sh 9f3a1c2                # deploy an exact commit
#
#   --only a.com,b.com    deploy to just these domains (leaves sites.conf alone)
#   --dry-run             show what would happen, touch nothing
#   --yes                 skip the confirmation prompt (for cron/automation)
#   --skip-lint           deploy even if php -l fails  (emergency use only)
#   --no-backup           skip pre-deploy backup       (not recommended)
#
# SITE LIST
#   Read from /etc/scos-deploy/sites.conf (root-owned, chmod 600).
#   NEVER commit the real site list to this repo - it is public.
#   See sites.conf.example for the format.
#
# WHAT IT DOES NOT DO
#   Never runs --delete against mu-plugins/ itself: that directory is shared
#   with mu-brighter-support-main and other must-use plugins this repo does not
#   own. Only the paths in OWNED_DIRS and OWNED_FILES are ever touched.
#

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

GITHUB_REPO="brighterwebsites/wp-scos-strategic-content-operating-system"
REPO_NAME="${GITHUB_REPO##*/}"
CONFIG_FILE="${SCOS_DEPLOY_CONFIG:-/etc/scos-deploy/sites.conf}"
HOME_ROOT="${SCOS_HOME_ROOT:-/home}"   # override only for testing
BACKUP_ROOT="/var/backups/scos-deploy"
LOG_FILE="/var/log/scos-deploy.log"
KEEP_BACKUPS=10

# Allowlist of what this repo owns. Anything not listed here is never copied,
# so a stray PHP file appearing at the repo root cannot ride along on a deploy.
OWNED_DIRS=(brighter-core site-essentials)
OWNED_FILES=(
    brighter-core-loader.php
    brighter-ga4-tracking.php
    site-essentials.php
    sitemap-diagnostic-logger.php
)

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

if [ -t 1 ]; then
    C_RED=$'\033[0;31m'; C_GRN=$'\033[0;32m'
    C_YEL=$'\033[0;33m'; C_BLU=$'\033[0;34m'; C_OFF=$'\033[0m'
else
    C_RED=''; C_GRN=''; C_YEL=''; C_BLU=''; C_OFF=''
fi

log()  { printf '%s\n' "$*"; printf '%s %s\n' "$(date -Is)" "$*" >>"$LOG_FILE" 2>/dev/null || true; }
info() { log "${C_BLU}==>${C_OFF} $*"; }
ok()   { log "${C_GRN}OK${C_OFF}  $*"; }
warn() { log "${C_YEL}WARN${C_OFF} $*"; }
err()  { log "${C_RED}ERROR${C_OFF} $*" >&2; }
die()  { err "$*"; exit 1; }

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

REF="main"
ONLY=""
DRY_RUN=0
ASSUME_YES=0
SKIP_LINT=0
DO_BACKUP=1
REF_SET=0

while [ $# -gt 0 ]; do
    case "$1" in
        --only)      ONLY="${2:-}"; [ -n "$ONLY" ] || die "--only needs a comma-separated domain list"; shift 2 ;;
        --only=*)    ONLY="${1#*=}"; shift ;;
        --dry-run)   DRY_RUN=1; shift ;;
        --yes|-y)    ASSUME_YES=1; shift ;;
        --skip-lint) SKIP_LINT=1; shift ;;
        --no-backup) DO_BACKUP=0; shift ;;
        -h|--help)   sed -n '2,31p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)          die "Unknown option: $1" ;;
        *)
            [ "$REF_SET" -eq 0 ] || die "Only one ref may be given (got '$REF' and '$1')"
            REF="$1"; REF_SET=1; shift ;;
    esac
done

# A leading slash here silently produced a malformed GitHub URL in v1.
REF="${REF#/}"
[ -n "$REF" ] || die "Empty ref"
case "$REF" in
    *[[:space:]]*) die "Ref must not contain whitespace: '$REF'" ;;
esac

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Must run as root (needs chown to the site user)."

for tool in wget unzip rsync git find; do
    command -v "$tool" >/dev/null 2>&1 || die "Required tool not found: $tool"
done

PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ] && [ "$SKIP_LINT" -eq 0 ]; then
    die "php CLI not found and --skip-lint not given. Refusing to deploy unlinted code."
fi

[ -f "$CONFIG_FILE" ] || die "Site list not found: $CONFIG_FILE (see scripts/sites.conf.example)"

# The site list contains domains, system usernames and server layout. If it is
# group- or world-readable, any shell user on this box can read it.
CONFIG_PERMS="$(stat -c '%a' "$CONFIG_FILE")"
CONFIG_OWNER="$(stat -c '%u' "$CONFIG_FILE")"
[ "$CONFIG_OWNER" = "0" ] || die "$CONFIG_FILE must be owned by root (currently uid $CONFIG_OWNER)."
case "$CONFIG_PERMS" in
    600|400) ;;
    *) die "$CONFIG_FILE must be chmod 600 (currently $CONFIG_PERMS). Run: chmod 600 $CONFIG_FILE" ;;
esac

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"

# ---------------------------------------------------------------------------
# Load and validate the site list
# ---------------------------------------------------------------------------

declare -a SITE_DOMAIN=() SITE_USER=()

parse_config() {
    local lineno=0 line domain user rest
    while IFS= read -r line || [ -n "$line" ]; do
        lineno=$((lineno + 1))
        line="${line%%#*}"
        line="$(printf '%s' "$line" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        [ -n "$line" ] || continue

        IFS='|' read -r domain user rest <<<"$line"
        domain="$(printf '%s' "${domain:-}" | tr -d '[:space:]')"
        user="$(printf '%s' "${user:-}"   | tr -d '[:space:]')"

        # Both fields must be present and well-formed. In v1 a malformed line
        # produced an empty $DOMAIN, which built paths like /home//public_html
        # and globbed rm -rf across every site's cache directory.
        [ -n "$domain" ] || die "$CONFIG_FILE line $lineno: missing domain"
        [ -n "$user" ]   || die "$CONFIG_FILE line $lineno: missing system user for '$domain'"

        case "$domain" in
            *[!a-zA-Z0-9.-]*|.*|-*|*..*) die "$CONFIG_FILE line $lineno: invalid domain '$domain'" ;;
        esac
        case "$user" in
            *[!a-zA-Z0-9._-]*|-*) die "$CONFIG_FILE line $lineno: invalid username '$user'" ;;
        esac

        SITE_DOMAIN+=("$domain")
        SITE_USER+=("$user")
    done <"$CONFIG_FILE"
}
parse_config

[ "${#SITE_DOMAIN[@]}" -gt 0 ] || die "No enabled sites in $CONFIG_FILE"

# --only filter
if [ -n "$ONLY" ]; then
    declare -a KEEP_DOMAIN=() KEEP_USER=()
    IFS=',' read -r -a WANTED <<<"$ONLY"
    for want in "${WANTED[@]}"; do
        want="$(printf '%s' "$want" | tr -d '[:space:]')"
        [ -n "$want" ] || continue
        found=0
        for i in "${!SITE_DOMAIN[@]}"; do
            if [ "${SITE_DOMAIN[$i]}" = "$want" ]; then
                KEEP_DOMAIN+=("${SITE_DOMAIN[$i]}"); KEEP_USER+=("${SITE_USER[$i]}"); found=1
            fi
        done
        [ "$found" -eq 1 ] || die "--only '$want' is not an enabled site in $CONFIG_FILE"
    done
    [ "${#KEEP_DOMAIN[@]}" -gt 0 ] || die "--only matched no sites"
    SITE_DOMAIN=("${KEEP_DOMAIN[@]}"); SITE_USER=("${KEEP_USER[@]}")
fi

# ---------------------------------------------------------------------------
# Temp workspace - mktemp, not a guessable PID path
# ---------------------------------------------------------------------------
#
# v1 used /tmp/mu-plugin-deploy-$$ with mkdir -p, which succeeds on an existing
# directory. Any shell user on a shared box could pre-create that path and have
# root extract into a directory they control, then swap a PHP file before the
# rsync. mktemp -d creates a fresh 0700 directory owned by root, or fails.

TEMP_DIR="$(mktemp -d -t scos-deploy-XXXXXXXXXX)"
chmod 700 "$TEMP_DIR"
cleanup() {
    local rc=$?
    if [ -n "${TEMP_DIR:-}" ] && [ -d "$TEMP_DIR" ]; then
        rm -rf "$TEMP_DIR"
    fi
    exit $rc
}
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------------------
# Resolve the ref to an exact commit
# ---------------------------------------------------------------------------

info "Resolving '$REF' in $GITHUB_REPO ..."

COMMIT=""
if printf '%s' "$REF" | grep -qE '^[0-9a-f]{40}$'; then
    COMMIT="$REF"
else
    # Tags take precedence over branches, matching git's own lookup order.
    LS_OUT="$(git ls-remote "https://github.com/$GITHUB_REPO.git" \
                "refs/tags/$REF^{}" "refs/tags/$REF" "refs/heads/$REF" 2>/dev/null || true)"
    COMMIT="$(printf '%s\n' "$LS_OUT" | awk 'NF{print $1; exit}')"
fi

[ -n "$COMMIT" ] || die "Could not resolve '$REF' to a commit. Check the branch/tag name."
printf '%s' "$COMMIT" | grep -qE '^[0-9a-f]{40}$' || die "Resolved ref is not a valid commit SHA: '$COMMIT'"

SHORT="${COMMIT:0:8}"
ok "$REF -> $COMMIT"

# ---------------------------------------------------------------------------
# Download by commit SHA
# ---------------------------------------------------------------------------
#
# Fetching by SHA rather than branch name means the extracted directory has a
# deterministic name, so we no longer guess it with `ls -d ... | head -1`.

ARCHIVE_URL="https://github.com/$GITHUB_REPO/archive/$COMMIT.zip"
SRC_DIR="$TEMP_DIR/$REPO_NAME-$COMMIT"

info "Downloading $ARCHIVE_URL"
wget --quiet --https-only --tries=3 --timeout=30 \
     -O "$TEMP_DIR/plugin.zip" "$ARCHIVE_URL" \
    || die "Download failed for commit $SHORT"

info "Extracting ..."
unzip -q "$TEMP_DIR/plugin.zip" -d "$TEMP_DIR" || die "Extract failed (corrupt archive?)"
[ -d "$SRC_DIR" ] || die "Expected directory not found after extract: $SRC_DIR"

# Sanity-check that this actually looks like the SCOS repo before we sync it.
for d in "${OWNED_DIRS[@]}"; do
    [ -d "$SRC_DIR/$d" ] || die "Downloaded tree is missing '$d/' - refusing to deploy."
done

ok "Extracted to $SRC_DIR"

# ---------------------------------------------------------------------------
# Lint gate - before any site is touched
# ---------------------------------------------------------------------------
#
# A parse error in a must-use plugin takes down the site AND wp-admin at the
# same time, on every site at once, and cannot be disabled from the dashboard.
# Catch it here, once, rather than on 30 live sites.

if [ "$SKIP_LINT" -eq 1 ]; then
    warn "PHP lint skipped (--skip-lint)."
else
    info "Linting PHP ..."
    LINT_FAILED=0
    LINT_COUNT=0
    while IFS= read -r -d '' phpfile; do
        LINT_COUNT=$((LINT_COUNT + 1))
        if ! LINT_OUT="$("$PHP_BIN" -l "$phpfile" 2>&1)"; then
            err "Syntax error: ${phpfile#$SRC_DIR/}"
            printf '%s\n' "$LINT_OUT" | sed 's/^/      /'
            LINT_FAILED=$((LINT_FAILED + 1))
        fi
    done < <(
        for d in "${OWNED_DIRS[@]}"; do
            if [ -d "$SRC_DIR/$d" ]; then
                find "$SRC_DIR/$d" -type f -name '*.php' -print0
            fi
        done
        for f in "${OWNED_FILES[@]}"; do
            if [ -f "$SRC_DIR/$f" ]; then
                printf '%s\0' "$SRC_DIR/$f"
            fi
        done
    )
    [ "$LINT_FAILED" -eq 0 ] || die "$LINT_FAILED file(s) failed php -l at commit $SHORT. Nothing deployed."
    ok "$LINT_COUNT PHP files linted clean"
fi

# ---------------------------------------------------------------------------
# Confirmation
# ---------------------------------------------------------------------------

COMMIT_INFO="$(wget --quiet --https-only --timeout=15 -O - \
    "https://api.github.com/repos/$GITHUB_REPO/commits/$COMMIT" 2>/dev/null \
    | tr -d '\n' \
    | sed -n 's/.*"message"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -c 200 || true)"

echo
echo "----------------------------------------"
echo "  Repo:    $GITHUB_REPO"
echo "  Ref:     $REF"
echo "  Commit:  $COMMIT"
[ -n "$COMMIT_INFO" ] && echo "  Message: $COMMIT_INFO"
echo "  Sites:   ${#SITE_DOMAIN[@]}"
for i in "${!SITE_DOMAIN[@]}"; do
    echo "           - ${SITE_DOMAIN[$i]} (${SITE_USER[$i]})"
done
echo "  Backup:  $([ "$DO_BACKUP" -eq 1 ] && echo "yes -> $BACKUP_ROOT" || echo "NO")"
[ "$DRY_RUN" -eq 1 ] && echo "  Mode:    ${C_YEL}DRY RUN - nothing will be written${C_OFF}"
echo "----------------------------------------"
echo

if [ "$DRY_RUN" -eq 0 ] && [ "$ASSUME_YES" -eq 0 ]; then
    if [ -t 0 ]; then
        printf 'Deploy this commit to the sites above? [y/N] '
        read -r REPLY
        case "$REPLY" in
            [yY]|[yY][eE][sS]) ;;
            *) info "Aborted."; exit 0 ;;
        esac
    else
        die "Not a terminal and --yes not given. Refusing to deploy unattended."
    fi
fi

# ---------------------------------------------------------------------------
# Per-site deploy
# ---------------------------------------------------------------------------

STAMP="$(date +%Y%m%d-%H%M%S)"
SUCCESS=0
FAILED=0
declare -a FAILED_SITES=()

backup_site() {
    # $1 = mu-plugins path, $2 = domain. Echoes the backup archive path.
    local mu="$1" domain="$2" dest tar_args=()
    dest="$BACKUP_ROOT/$domain/$STAMP-$SHORT.tar.gz"
    mkdir -p "$(dirname "$dest")"
    chmod 700 "$BACKUP_ROOT/$domain"

    for d in "${OWNED_DIRS[@]}"; do
        if [ -d "$mu/$d" ]; then tar_args+=("$d"); fi
    done
    for f in "${OWNED_FILES[@]}"; do
        if [ -f "$mu/$f" ]; then tar_args+=("$f"); fi
    done

    if [ "${#tar_args[@]}" -eq 0 ]; then
        # First-time deploy: nothing to back up.
        printf ''
        return 0
    fi

    tar -czf "$dest" -C "$mu" "${tar_args[@]}" 2>/dev/null || return 1
    chmod 600 "$dest"
    printf '%s' "$dest"
}

restore_site() {
    # $1 = backup archive, $2 = mu-plugins path
    local archive="$1" mu="$2"
    if [ -z "$archive" ] || [ ! -f "$archive" ]; then
        return 1
    fi
    for d in "${OWNED_DIRS[@]}"; do
        if [ -d "$mu/$d" ]; then rm -rf "${mu:?}/${d:?}"; fi
    done
    tar -xzf "$archive" -C "$mu"
}

prune_backups() {
    local domain="$1"
    local dir="$BACKUP_ROOT/$domain"
    [ -d "$dir" ] || return 0

    # Read from a process substitution rather than a pipeline. Under
    # `set -o pipefail` a pipeline starting with a non-matching glob exits
    # non-zero (ls returns 2), which `set -e` turns into an aborted run - on the
    # very first deploy to a site, when there is nothing to prune yet.
    local old
    while IFS= read -r old; do
        if [ -n "$old" ]; then rm -f "$old"; fi
    done < <(
        find "$dir" -maxdepth 1 -type f -name '*.tar.gz' -printf '%T@ %p\n' 2>/dev/null \
            | sort -rn \
            | tail -n +$((KEEP_BACKUPS + 1)) \
            | cut -d' ' -f2-
    )
    return 0
}

for i in "${!SITE_DOMAIN[@]}"; do
    DOMAIN="${SITE_DOMAIN[$i]}"
    SITE_USER_NAME="${SITE_USER[$i]}"
    SITE_PATH="$HOME_ROOT/$DOMAIN/public_html"
    MU_PLUGINS_PATH="$SITE_PATH/wp-content/mu-plugins"

    echo
    info "$DOMAIN (user: $SITE_USER_NAME)"

    site_failed() {
        err "  $DOMAIN: $1"
        FAILED=$((FAILED + 1))
        FAILED_SITES+=("$DOMAIN")
    }

    # -- Guard: the site must actually exist ------------------------------
    #
    # v1 ran `mkdir -p "$MU_PLUGINS_PATH"` unconditionally, so a typo'd domain
    # silently created /home/<typo>/public_html/wp-content/mu-plugins, deployed
    # into it, chowned it, and reported success while the real site went stale.
    if [ ! -f "$SITE_PATH/wp-config.php" ]; then
        site_failed "no wp-config.php at $SITE_PATH - not a WordPress install, skipping"
        continue
    fi
    if [ ! -d "$SITE_PATH/wp-content" ]; then
        site_failed "no wp-content at $SITE_PATH, skipping"
        continue
    fi
    if ! id -u "$SITE_USER_NAME" >/dev/null 2>&1; then
        site_failed "system user '$SITE_USER_NAME' does not exist, skipping"
        continue
    fi

    if [ "$DRY_RUN" -eq 1 ]; then
        for d in "${OWNED_DIRS[@]}"; do
            if [ -d "$SRC_DIR/$d" ]; then echo "  would sync  $d/ -> $MU_PLUGINS_PATH/$d/"; fi
        done
        for f in "${OWNED_FILES[@]}"; do
            if [ -f "$SRC_DIR/$f" ]; then echo "  would copy  $f -> $MU_PLUGINS_PATH/$f"; fi
        done
        echo "  would chown -R $SITE_USER_NAME:$SITE_USER_NAME (owned paths only)"
        SUCCESS=$((SUCCESS + 1))
        continue
    fi

    if [ ! -d "$MU_PLUGINS_PATH" ]; then
        mkdir -p "$MU_PLUGINS_PATH"
        chown "$SITE_USER_NAME:$SITE_USER_NAME" "$MU_PLUGINS_PATH"
        chmod 755 "$MU_PLUGINS_PATH"
        info "  created $MU_PLUGINS_PATH"
    fi

    # -- Backup ------------------------------------------------------------
    BACKUP_PATH=""
    if [ "$DO_BACKUP" -eq 1 ]; then
        if ! BACKUP_PATH="$(backup_site "$MU_PLUGINS_PATH" "$DOMAIN")"; then
            site_failed "backup failed - refusing to deploy without a rollback point"
            continue
        fi
        if [ -n "$BACKUP_PATH" ]; then info "  backed up to $BACKUP_PATH"; fi
    fi

    # -- Sync --------------------------------------------------------------
    #
    # --delete is scoped to each owned directory, never to mu-plugins itself,
    # which is shared with mu-brighter-support-main and other MU plugins.
    SYNC_OK=1
    for d in "${OWNED_DIRS[@]}"; do
        if [ -d "$SRC_DIR/$d" ]; then
            if rsync -a --delete --no-owner --no-group \
                     "$SRC_DIR/$d/" "$MU_PLUGINS_PATH/$d/"; then
                info "  synced $d/"
            else
                err "  rsync failed for $d/"
                SYNC_OK=0
                break
            fi
        fi
    done

    if [ "$SYNC_OK" -eq 1 ]; then
        for f in "${OWNED_FILES[@]}"; do
            if [ -f "$SRC_DIR/$f" ]; then
                if ! cp -f "$SRC_DIR/$f" "$MU_PLUGINS_PATH/$f"; then
                    err "  copy failed for $f"
                    SYNC_OK=0
                    break
                fi
            fi
        done
    fi

    # v1 tested `if [ $? -ne 0 ]` here, which read the exit status of the last
    # `[ -f ] && cp` test rather than the rsync loop - so rsync failures were
    # reported as successful deploys. SYNC_OK tracks it explicitly instead.
    if [ "$SYNC_OK" -ne 1 ]; then
        if [ "$DO_BACKUP" -eq 1 ] && [ -n "$BACKUP_PATH" ]; then
            warn "  rolling back $DOMAIN from $BACKUP_PATH"
            if restore_site "$BACKUP_PATH" "$MU_PLUGINS_PATH"; then
                warn "  rolled back"
            else
                err "  ROLLBACK FAILED - $DOMAIN needs manual attention, archive: $BACKUP_PATH"
            fi
        fi
        site_failed "sync failed"
        continue
    fi

    # -- Permissions, scoped to owned paths only ---------------------------
    #
    # v1 chmod/chown'd all of mu-plugins, including plugins this repo does not
    # own, contradicting its own "this directory is shared" comment.
    PERM_OK=1
    for d in "${OWNED_DIRS[@]}"; do
        if [ -d "$MU_PLUGINS_PATH/$d" ]; then
            find "$MU_PLUGINS_PATH/$d" -type d -exec chmod 755 {} + || PERM_OK=0
            find "$MU_PLUGINS_PATH/$d" -type f -exec chmod 644 {} + || PERM_OK=0
            chown -R "$SITE_USER_NAME:$SITE_USER_NAME" "$MU_PLUGINS_PATH/$d" || PERM_OK=0
        fi
    done
    for f in "${OWNED_FILES[@]}"; do
        if [ -f "$MU_PLUGINS_PATH/$f" ]; then
            chmod 644 "$MU_PLUGINS_PATH/$f" || PERM_OK=0
            chown "$SITE_USER_NAME:$SITE_USER_NAME" "$MU_PLUGINS_PATH/$f" || PERM_OK=0
        fi
    done
    [ "$PERM_OK" -eq 1 ] || warn "  some permission changes failed on $DOMAIN - check manually"

    # -- Record what was deployed ------------------------------------------
    printf '%s\n' "$COMMIT" >"$MU_PLUGINS_PATH/.scos-deployed-commit"
    chmod 644 "$MU_PLUGINS_PATH/.scos-deployed-commit"
    chown "$SITE_USER_NAME:$SITE_USER_NAME" "$MU_PLUGINS_PATH/.scos-deployed-commit"

    # -- Cache ------------------------------------------------------------
    # $DOMAIN is validated at parse time, so these paths cannot collapse to a
    # parent directory the way an empty domain did in v1.
    if [ -d "$SITE_PATH/lscache" ]; then
        find "$SITE_PATH/lscache" -mindepth 1 -delete 2>/dev/null || true
    fi
    if [ -d "/usr/local/lsws/cachedata/$DOMAIN" ]; then
        find "/usr/local/lsws/cachedata/$DOMAIN" -mindepth 1 -delete 2>/dev/null || true
    fi

    prune_backups "$DOMAIN"

    ok "  $DOMAIN deployed at $SHORT"
    SUCCESS=$((SUCCESS + 1))
done

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

echo
echo "========================================"
echo "  Deployment summary"
echo "  Ref:        $REF"
echo "  Commit:     $COMMIT"
echo "  Successful: $SUCCESS"
echo "  Failed:     $FAILED"
if [ "${#FAILED_SITES[@]}" -gt 0 ]; then
    echo "  Failed sites: ${FAILED_SITES[*]}"
fi
[ "$DRY_RUN" -eq 1 ] && echo "  (dry run - nothing was written)"
echo "========================================"

# v1 always exited 0, so nothing downstream could detect a bad deploy.
[ "$FAILED" -eq 0 ] || exit 1
exit 0
