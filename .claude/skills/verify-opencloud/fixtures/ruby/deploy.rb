# Opencloud runs this as a deploy command before the new version gets traffic: bundle exec ruby deploy.rb <cell>
require_relative 'verify'

cell = ARGV.first || 'unknown'
puts "deploy-marker-#{cell}"

url = Verify.env('DATABASE_URL')

if url
  db = Verify::Database.new(url)
  db.query("CREATE TABLE IF NOT EXISTS verify_hits (id #{Verify::ID[db.driver]}, created_at TIMESTAMP NOT NULL)")
  db.query("CREATE TABLE IF NOT EXISTS verify_deploys (id #{Verify::ID[db.driver]}, cell VARCHAR(100) NOT NULL, created_at TIMESTAMP NOT NULL)")
  db.query('INSERT INTO verify_deploys (cell, created_at) VALUES (?, CURRENT_TIMESTAMP)', cell)
  db.close
  puts 'The deploy command wrote one row to verify_deploys.'
end
