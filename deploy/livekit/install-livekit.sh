#!/usr/bin/env bash
#
# Install or upgrade the LiveKit SFU.
#
# Deliberately NOT called by deploy/deploy.sh. Restarting this process ends every
# live huddle instantly — unlike Reverb, whose restart a one-to-one call survives
# because its media never touches this server. Run this by hand, out of hours.
#
# Version is pinned in deploy/livekit/VERSION rather than installed with
# `curl -sSL https://get.livekit.io | bash`, which fetches "latest". A silent
# server bump can change the signalling protocol and break the livekit-client
# pinned in package.json, and the failure mode is "Join spins forever" with
# nothing in the Laravel log.
#
# Rollback is a symlink swap plus a restart: old versions are left installed.
#
#   sudo deploy/livekit/install-livekit.sh
#   sudo supervisorctl restart oaktree-livekit

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
VERSION="$(cat "${HERE}/VERSION")"
ARCHIVE="livekit_${VERSION#v}_linux_amd64.tar.gz"

echo "Installing LiveKit ${VERSION}…"

curl -fsSL -o "/tmp/${ARCHIVE}" \
  "https://github.com/livekit/livekit/releases/download/${VERSION}/${ARCHIVE}"

# Refuses to install anything we did not expect. Update SHA256SUMS in the same
# commit that changes VERSION.
( cd /tmp && sha256sum -c "${HERE}/SHA256SUMS" )

tar -xzf "/tmp/${ARCHIVE}" -C /tmp livekit-server
install -m 0755 /tmp/livekit-server "/usr/local/bin/livekit-server-${VERSION}"
ln -sfn "/usr/local/bin/livekit-server-${VERSION}" /usr/local/bin/livekit-server
rm -f "/tmp/${ARCHIVE}" /tmp/livekit-server

echo
echo "Installed. Now, and only when no huddle is running:"
echo "  sudo supervisorctl restart oaktree-livekit"
echo
echo "Rollback:"
echo "  sudo ln -sfn /usr/local/bin/livekit-server-<older> /usr/local/bin/livekit-server"
echo "  sudo supervisorctl restart oaktree-livekit"
