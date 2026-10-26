variable "name" {
  type        = string
  description = "The name of the database cluster on DigitalOcean."
}

variable "engine_version" {
  type        = string
  description = "The major version of Valkey, for example 8."
}

variable "size" {
  type        = string
  description = "The size slug, for example db-s-1vcpu-1gb."
}

variable "region" {
  type        = string
  description = "The region slug. Use the region of the Kubernetes cluster."
}

variable "vpc_id" {
  type        = string
  description = "The VPC of the Kubernetes cluster, so the pods connect over the private network."
}

variable "kubernetes_cluster_id" {
  type        = string
  description = "The only cluster that the firewall lets in."
}
