# A Spaces key with full access, for OpenTofu only. The bucket module needs it, because the provider creates a bucket
# through the S3 API of Spaces, and the API token cannot do that. It is a separate stack, because a provider cannot
# take its credentials from a resource of the same apply. The app never gets this key.
# The provider reads the API token from DIGITALOCEAN_TOKEN.

terraform {
  required_version = ">= 1.13.0"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "2.106.0"
    }
  }
}

resource "digitalocean_spaces_key" "this" {
  name = var.name

  grant {
    bucket     = ""
    permission = "fullaccess"
  }
}
