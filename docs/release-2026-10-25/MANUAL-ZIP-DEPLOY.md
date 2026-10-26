# YD SOFT 2.20.0 by hand while GitHub Actions is blocked (runbook H2)

yd-soft GitHub Actions does not run ("recent account payments have failed"), so neither CI nor `release.yml` can build
the release. This is the manual path. It installs exactly the reviewed commit on both shops, keeps a tested way back,
and never creates a GitHub release that the shops' updaters could pick up on their own.

## 0. Rules

- Build only from the reviewed, merged commit (`git ls-files`, `.github/scripts/build-zip.sh`); never from a working
  tree with changes, never a zip edited by hand.
- **Never push yd-soft `master` before both shops are prepared, and never re-run an old failed release job.** `master`
  holds 2.10.0 (payment gate), never released; a release job on it would publish v2.10.0, and both shops' YD updaters
  (authorised, checking GitHub) would offer it. A later release from the merged release branch is v2.20.0
  (`prepare-version.sh` releases the version in `yd-soft.php` when it is above the last tag): the same version as the
  hand-installed one, so the updaters offer nothing.
- WordPress plugin auto-updates are off on both shops (read 2026-10-25); keep them off.
- Trend Home first, then Outlet Perdele, the same day, inside the API go-live (runbook step 3), after the API 2.46.0
  migrations and deploy.

## 1. Build and fingerprint (owner's Mac)

```bash
cd yd-soft && git status --short          # must print nothing
git rev-parse HEAD                         # the reviewed commit
bash .github/scripts/build-zip.sh 2.20.0   # dist/yd-soft.zip
shasum -a 256 dist/yd-soft.zip
unzip -p dist/yd-soft.zip yd-soft/yd-soft.php | grep -m1 'Version:'   # 2.20.0
unzip -Z1 dist/yd-soft.zip | grep -c '^yd-soft/tests/'                # 0
```

Record the commit and the SHA-256 in the go-live log.

## 2. The way back (already prepared)

`~/arasya-local-backups/2026-10-25-pre-go-live/yd-soft-2.9.1-live-rollback.zip` is the plugin folder as it ran on
2026-10-25 (identical on both shops), SHA-256
`59d87ea37e115f46c3d0574b4ab2d7bd80f92aca0a97c045f0586c990ddd6307`. The same folder holds the databases and
`wp-content` of both shops. Take a fresh database dump of each shop right before step 3 (`wp db export`), as on
2026-10-25.

## 3. Install on one shop

```bash
scp dist/yd-soft.zip trendhome.ro:yd-soft-2.20.0.zip
ssh trendhome.ro
  sha256sum ~/yd-soft-2.20.0.zip                      # equal to step 1
  cd ~/public_html
  wp plugin list --name=yd-soft --fields=name,status,version
  wp db export ~/yd-pre-2.20.0-$(date -u +%Y%m%dT%H%M%SZ).sql --single-transaction --quick
  wp plugin install ~/yd-soft-2.20.0.zip --force      # replaces the folder; the plugin stays active
  wp plugin get yd-soft --field=version               # 2.20.0
  wp yd-arasya operations status                      # every switch off
  wp option get yd_soft_db_version                    # 2.7.0 (the core schema does not change)
```

Then check, before the second shop:
- the connector still delivers: `wp eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yd_arasya_outbox WHERE state IN (\"pending\",\"retry\")");'`
  goes back to 0 within minutes, and the API health shows the shop's last contact;
- the WordPress order screens open; checkout is not tested with a real customer's data;
- the production fences are unchanged (`enforce`), the AWB buttons work as before while `awb` is off.

## 4. Way back (one shop)

```bash
scp ~/arasya-local-backups/2026-10-25-pre-go-live/yd-soft-2.9.1-live-rollback.zip trendhome.ro:
ssh trendhome.ro 'cd ~/public_html && wp plugin install ~/yd-soft-2.9.1-live-rollback.zip --force && wp plugin get yd-soft --field=version'
```

The tables 2.20.0 created stay (additive, unused by 2.9.1). Switch every operations setting off first if any was on.

## 5. Later, when GitHub Actions works again

Merge the release branch into `master` only after both shops run 2.20.0 from the same commit; the release job then
publishes v2.20.0, which the updaters treat as already installed.
