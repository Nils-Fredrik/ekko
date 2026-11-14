terraform {
  required_version = ">= 1.5.0"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 6.0"
    }
  }

  # The bucket is provisioned separately; see README.md.
  backend "gcs" {
    prefix = "ekko/production"
  }
}

provider "google" {
  project = var.project_id
  region  = var.region
}
