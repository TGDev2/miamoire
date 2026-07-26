#!/bin/sh
set -e

# Les migrations tournent au démarrage du conteneur, pas via un mécanisme propre
# à un hébergeur (release_command chez Fly, preDeploy ailleurs, rien du tout sur
# certains). C'est ce qui rend la même image déployable partout sans adaptation.
#
# La commande est idempotente : au deuxième démarrage elle ne fait rien.
echo "→ Migrations de la base"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# L'hébergeur impose son port par $PORT ; Caddy doit écouter dessus.
export SERVER_NAME=":${PORT:-8080}"

echo "→ Démarrage de FrankenPHP sur ${SERVER_NAME}"
exec frankenphp run --config /etc/frankenphp/Caddyfile
