#!/usr/bin/env sh
# Apply or remove artificial latency via Linux tc netem (requires sudo).
#
# Localhost bench (server and client on same host):
#   sudo ./netem_latency.sh apply 50ms lo
#   php benchmark/fixtures/bench.php latency --port=19091 --samples=200
#   sudo ./netem_latency.sh clear lo
#
# Remote client (delay on egress interface toward server):
#   sudo ./netem_latency.sh apply 50ms eth0
#   php remote_latency.php --host=192.168.1.66 --port=19091 --samples=200 --warmup=20

set -e

ACTION="${1:-}"
DELAY="${2:-50ms}"
IFACE="${3:-${NETEM_IFACE:-lo}}"

usage() {
    echo "Usage: $0 apply <delay> [iface]   # e.g. apply 50ms lo" >&2
    echo "       $0 clear [iface]" >&2
    exit 1
}

[ -n "$ACTION" ] || usage

if [ "$ACTION" = "apply" ]; then
    sudo tc qdisc replace dev "$IFACE" root netem delay "$DELAY"
    echo "netem: applied delay=$DELAY on $IFACE"
elif [ "$ACTION" = "clear" ]; then
    sudo tc qdisc del dev "$IFACE" root 2>/dev/null || true
    echo "netem: cleared $IFACE"
else
    usage
fi
