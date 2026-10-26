output "endpoint" {
  value = "https://${digitalocean_spaces_bucket.this.region}.digitaloceanspaces.com"
}

output "region" {
  value = digitalocean_spaces_bucket.this.region
}

output "bucket" {
  value = digitalocean_spaces_bucket.this.name
}

output "access_key_id" {
  value = digitalocean_spaces_key.this.access_key
}

output "secret_key" {
  value     = digitalocean_spaces_key.this.secret_key
  sensitive = true
}
