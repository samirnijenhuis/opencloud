#!/usr/bin/env bash
# Creates an OrbStack machine with plain k3s and a registry with basic auth on port 5000.
# It stands in for a cluster that the owner connects. Usage: plain-k3s.sh <machine>
set -euo pipefail

MACHINE=${1:?Usage: plain-k3s.sh <machine>}
K3S_VERSION=v1.36.5+k3s1
REGISTRY_USER=${REGISTRY_USER:-opencloud}
REGISTRY_PASSWORD=${REGISTRY_PASSWORD:-registry-test-password}

on_machine() {
    orb -m "$MACHINE" sudo "$@"
}

orb create "ubuntu:noble" "$MACHINE" >/dev/null
IP=$(orb -m "$MACHINE" hostname -I | awk '{ print $1 }')

on_machine mkdir -p /etc/rancher/k3s
on_machine tee /etc/rancher/k3s/registries.yaml >/dev/null <<EOF
mirrors:
  "$IP:5000":
    endpoint:
      - "http://$IP:5000"
configs:
  "$IP:5000":
    auth:
      username: $REGISTRY_USER
      password: $REGISTRY_PASSWORD
EOF

on_machine sh -c "curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION=$K3S_VERSION sh -s - --disable traefik" >/dev/null

for _ in $(seq 1 60); do
    on_machine k3s kubectl get nodes 2>/dev/null | grep -q ' Ready ' && break
    sleep 3
done

HTPASSWD=$(php -r 'echo $argv[1].":".password_hash($argv[2], PASSWORD_BCRYPT);' "$REGISTRY_USER" "$REGISTRY_PASSWORD")

on_machine k3s kubectl apply -f - >/dev/null <<EOF
apiVersion: v1
kind: Namespace
metadata:
  name: test-registry
---
apiVersion: v1
kind: Secret
metadata:
  name: htpasswd
  namespace: test-registry
stringData:
  htpasswd: '$HTPASSWD'
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: registry
  namespace: test-registry
spec:
  selector:
    matchLabels:
      app: registry
  template:
    metadata:
      labels:
        app: registry
    spec:
      containers:
        - name: registry
          image: registry:3
          env:
            - name: REGISTRY_AUTH
              value: htpasswd
            - name: REGISTRY_AUTH_HTPASSWD_REALM
              value: test-registry
            - name: REGISTRY_AUTH_HTPASSWD_PATH
              value: /auth/htpasswd
          ports:
            - containerPort: 5000
          volumeMounts:
            - name: auth
              mountPath: /auth
            - name: data
              mountPath: /var/lib/registry
      volumes:
        - name: auth
          secret:
            secretName: htpasswd
        - name: data
          hostPath:
            path: /var/lib/test-registry
            type: DirectoryOrCreate
---
apiVersion: v1
kind: Service
metadata:
  name: registry
  namespace: test-registry
spec:
  type: LoadBalancer
  selector:
    app: registry
  ports:
    - port: 5000
      targetPort: 5000
EOF

for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' -u "$REGISTRY_USER:$REGISTRY_PASSWORD" "http://$IP:5000/v2/" || true)
    [[ $code == 200 ]] && break
    sleep 3
done
[[ $code == 200 ]] || { echo "The registry on $IP:5000 does not answer 200 (got $code)." >&2; exit 1; }

echo "Machine: $MACHINE"
echo "IP: $IP"
echo "Registry: $IP:5000 user $REGISTRY_USER"
echo "Kubeconfig:"
on_machine cat /etc/rancher/k3s/k3s.yaml | sed "s#https://127.0.0.1:6443#https://$IP:6443#"
