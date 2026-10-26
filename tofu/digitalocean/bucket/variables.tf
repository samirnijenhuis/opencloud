variable "name" {
  type        = string
  description = "The name of the bucket, which is unique in all of Spaces, and of its key."
}

variable "region" {
  type        = string
  description = "The region slug. Use the region of the Kubernetes cluster."
}
