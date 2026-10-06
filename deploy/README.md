# Cloud deployment — prepared, not yet deployed

No public deployment URL exists yet. An authenticated GCP CLI was detected, but the intended project, billing allowance and DNS hostname must be supplied before provisioning. A Gemini API key is also needed to verify a real model response. Local Docker is not a cloud deployment.

## Services and secrets

The prepared stack runs the full React/PHP/MySQL app on one **GCP Compute Engine** Ubuntu 24.04 VM, with **Persistent Disk** for the Docker database volume and **Caddy** for HTTPS. It does not require Cloud SQL, Cloud Storage, RDS or S3. phpMyAdmin is excluded from the public stack. Only ports 80/443 are public; SSH uses IAP. The VM needs no service account or cloud API scopes to run these containers.

Use an eligible `e2-micro` in `us-central1`, `us-east1` or `us-west1` with at most 30 GB standard persistent disk when the account's free-tier allowance permits. Build images locally to avoid exhausting VM memory. The full stack can still exceed 1 GB RAM; a larger VM uses credits or incurs charges. External IPv4, egress, snapshots, Gemini calls and quotas must be checked against the actual account; this plan does **not** guarantee zero cost. [GCP Free Tier](https://docs.cloud.google.com/free/docs/free-cloud-features).

Create a separate, git-ignored `.env.production` with fresh random MySQL/JWT secrets, `GEMINI_API_KEY`, `GEMINI_MODEL`, and `APP_DOMAIN=tasks.your-domain.example`. Do not copy the local assessment credentials to a public deployment. Upload this file over SSH, set permissions to `600`, and pass it to Compose with `--env-file`. Secrets are injected into server containers at runtime, never bundled into React or Docker image layers. This demo uses a protected host file, not Secret Manager; Docker administrators can read container environments. A hardened deployment should retrieve secrets from Secret Manager through a narrowly scoped VM identity.

## Provision (after project and cost approval)

The commands below are a runbook, not evidence of a completed deployment. Replace the project, zone and instance values. A project administrator must enable Compute Engine/IAP, authorize OS Login/IAP SSH, and confirm billing/quotas. The example assumes the `default` VPC exists.

```powershell
$project = 'YOUR_APPROVED_PROJECT'
$zone = 'us-central1-a'
$instance = 'goqii-assessment'
gcloud services enable compute.googleapis.com iap.googleapis.com --project=$project
gcloud compute instances create $instance --project=$project --zone=$zone --machine-type=e2-micro --image-family=ubuntu-2404-lts-amd64 --image-project=ubuntu-os-cloud --boot-disk-size=30GB --boot-disk-type=pd-standard --tags=goqii-web --metadata=enable-oslogin=TRUE --no-service-account --no-scopes --shielded-secure-boot
gcloud compute firewall-rules create goqii-web --project=$project --network=default --allow=tcp:80,tcp:443,udp:443 --source-ranges=0.0.0.0/0 --target-tags=goqii-web
gcloud compute firewall-rules create goqii-iap-ssh --project=$project --network=default --allow=tcp:22 --source-ranges=35.235.240.0/20 --target-tags=goqii-web
gcloud compute instances describe $instance --project=$project --zone=$zone --format='get(networkInterfaces[0].accessConfigs[0].natIP)'
```

Point the DNS A record for `APP_DOMAIN` at the returned IP. Review existing VPC firewall rules: adding an IAP rule does not remove any pre-existing broad SSH allowance. The ephemeral IP can change after stop/start; a reserved address may have additional cost.

Build on your workstation and transfer only runtime assets (no local database or `.env`):

```powershell
docker build --platform linux/amd64 -f Dockerfile.api -t goqii-api:deployment .
docker build --platform linux/amd64 -f Dockerfile.web -t goqii-web:deployment .
docker save -o "$env:TEMP\goqii-images.tar" goqii-api:deployment goqii-web:deployment
tar -cf "$env:TEMP\goqii-runtime.tar" schema.sql deploy
gcloud compute scp "$env:TEMP\goqii-images.tar" "$env:TEMP\goqii-runtime.tar" .env.production "${instance}:~/" --project=$project --zone=$zone --tunnel-through-iap
gcloud compute ssh $instance --project=$project --zone=$zone --tunnel-through-iap
```

On the VM:

```bash
mkdir -p ~/goqii
tar -xf ~/goqii-runtime.tar -C ~/goqii
install -m 600 ~/.env.production ~/goqii/.env.production
rm ~/.env.production
cd ~/goqii
bash deploy/install-docker.sh
sudo docker load -i ~/goqii-images.tar
sudo docker compose --env-file .env.production -f deploy/compose.cloud.yml up -d
read -rsp 'New admin password (12-72 bytes): ' admin_password; printf '\n'
printf '%s' "$admin_password" | sudo docker compose --env-file .env.production -f deploy/compose.cloud.yml exec -T api php /opt/goqii/api.php create-user admin@example.com Admin
unset admin_password
```

Verify `https://APP_DOMAIN/api/health`, login, CRUD, ownership restrictions and one Gemini suggestion before claiming deployment success. Record the actual URL, project, region, machine type, tested commit and date here. Keep database backups off the VM and test restores; this single VM is not highly available. Removing a VM/boot disk or running Compose `down -v` can destroy the database. Stop/delete demo resources after evaluation according to the user's retention preference; stopping alone may not eliminate disk/IP charges.

## CI/CD status

`.github/workflows/ci.yml` defines GitHub Actions CI for PHP syntax, AI parser tests, Node syntax, Compose validation and both Docker builds. It runs only after the workflow is pushed to GitHub; no remote run is claimed. **CD is not wired up**: deployment is manual via the runbook above. No cloud credentials are stored in GitHub. Future CD should use GitHub OIDC/Workload Identity Federation and a protected deployment environment, not a committed service-account key.
