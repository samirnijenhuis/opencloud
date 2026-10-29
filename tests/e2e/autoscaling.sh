#!/usr/bin/env bash
# Proves that the autoscaler of an environment reads CPU metrics, scales web up under load, and scales it down after.
# Usage: autoscaling.sh <machine> <environment namespace>
# The environment needs max_instances above min_instances and a low cpu_target. k3s ships metrics-server.
set -euo pipefail

MACHINE=${1:?Usage: autoscaling.sh <machine> <environment namespace>}
NAMESPACE=${2:?}
WORKERS=${WORKERS:-6}

kc() { orb -m "$MACHINE" sudo k3s kubectl "$@"; }

state() {
    local replicas utilization desired
    replicas=$(kc -n "$NAMESPACE" get deploy web -o jsonpath='{.status.readyReplicas}')
    utilization=$(kc -n "$NAMESPACE" get hpa web -o jsonpath='{.status.currentMetrics[0].resource.current.averageUtilization}')
    desired=$(kc -n "$NAMESPACE" get hpa web -o jsonpath='{.status.desiredReplicas}')
    echo "$(date +%H:%M:%S) ready=${replicas:-0} desired=${desired:-?} cpu=${utilization:-unknown}%"
}

wait_until() {
    local seconds=$1 check=$2 deadline=$((SECONDS + $1))
    while true; do
        line=$(state)
        echo "$line"
        [[ $line =~ $check ]] && return 0
        ((SECONDS < deadline)) || { echo "Timed out after ${seconds}s waiting for: $check" >&2; return 1; }
        sleep 15
    done
}

kc -n "$NAMESPACE" get hpa web -o jsonpath='{.spec.minReplicas} {.spec.maxReplicas} {.spec.metrics[0].resource.target.averageUtilization}{"\n"}' |
    awk '{ print "The HPA scales web between " $1 " and " $2 " pods at " $3 "% CPU." }'
MAX=$(kc -n "$NAMESPACE" get hpa web -o jsonpath='{.spec.maxReplicas}')
MIN=$(kc -n "$NAMESPACE" get hpa web -o jsonpath='{.spec.minReplicas}')

echo "== The HPA reads CPU metrics"
wait_until 300 "cpu=[0-9]+%"

echo "== Load with $WORKERS workers from namespace $NAMESPACE"
kc apply -f - >/dev/null <<EOF
apiVersion: apps/v1
kind: Deployment
metadata:
  name: load
  namespace: $NAMESPACE
spec:
  replicas: $WORKERS
  selector:
    matchLabels:
      app: load
  template:
    metadata:
      labels:
        app: load
    spec:
      containers:
        - name: load
          image: busybox:1.37
          command: [sh, -c, 'while true; do wget -q -O /dev/null http://web.$NAMESPACE.svc.cluster.local:8080/ || true; done']
EOF
wait_until 600 "ready=$MAX "

echo "== Stop the load"
kc -n "$NAMESPACE" delete deployment load --wait=false >/dev/null
wait_until 900 "ready=$MIN desired=$MIN "
