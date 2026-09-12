-- ─── PostgreSQL Initialization Script ────────────────────────────────────────
-- This script runs automatically on first container startup.
-- It enables the pgvector extension required for AI embedding storage.

-- Enable pgvector extension in the drupal database
CREATE EXTENSION IF NOT EXISTS vector;

-- Verify installation
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM pg_extension WHERE extname = 'vector'
  ) THEN
    RAISE NOTICE 'pgvector extension installed successfully (version: %)',
      (SELECT extversion FROM pg_extension WHERE extname = 'vector');
  ELSE
    RAISE EXCEPTION 'pgvector extension FAILED to install!';
  END IF;
END;
$$;
