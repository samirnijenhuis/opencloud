# Reports what the opencloud verification matrix checks: env values, the build, the databases, and the cache.
require 'digest'
require 'mysql2'
require 'pg'
require 'redis'
require 'uri'

module Verify
  # The generic env profile writes mysql:// and postgresql:// URLs.
  DRIVERS = { 'mysql' => 'mysql', 'postgres' => 'pgsql', 'postgresql' => 'pgsql' }.freeze
  ID = { 'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY', 'pgsql' => 'SERIAL PRIMARY KEY' }.freeze

  class Database
    attr_reader :driver

    def initialize(url)
      uri = URI.parse(url)
      @driver = DRIVERS[uri.scheme] or raise ArgumentError, "#{uri.scheme}:// is not a mysql:// or postgresql:// URL."
      @client = if @driver == 'mysql'
        Mysql2::Client.new(host: uri.host, port: uri.port, username: URI.decode_uri_component(uri.user.to_s),
                           password: URI.decode_uri_component(uri.password.to_s), database: uri.path.delete_prefix('/'), connect_timeout: 5)
      else
        PG.connect(url, connect_timeout: 5)
      end
    end

    # Runs one statement and returns the rows as arrays. A ? is the placeholder of the one parameter.
    def query(sql, *params)
      return @client.prepare(sql).execute(*params, as: :array).to_a if @driver == 'mysql'

      @client.exec_params(sql.sub('?', '$1'), params).values
    end

    def close
      @client.close
    end
  end

  module_function

  def env(key)
    value = ENV.fetch(key, '')
    value.empty? ? nil : value
  end

  def database
    url = env('DATABASE_URL')
    return nil if url.nil?

    db = Database.new(url)
    db.query('INSERT INTO verify_hits (created_at) VALUES (CURRENT_TIMESTAMP)')
    hits, deploys = %w[verify_hits verify_deploys].map { |table| db.query("SELECT COUNT(*) FROM #{table}")[0][0].to_i }
    { driver: db.driver, ok: true, hits: hits, deploys: deploys }
  rescue StandardError => e
    { driver: DRIVERS[URI.parse(url).scheme], ok: false, error: e.message }
  ensure
    db&.close
  end

  # Opencloud sets one <NAME>_DATABASE_URL value for each database that is not the default.
  def extra
    ENV.keys.select { |key| key.end_with?('_DATABASE_URL') }.sort.to_h do |key|
      db = Database.new(ENV.fetch(key))
      [key.delete_suffix('_DATABASE_URL').downcase, { driver: db.driver, ok: db.query('SELECT 1')[0][0].to_i == 1 }]
    rescue StandardError => e
      [key.delete_suffix('_DATABASE_URL').downcase, { driver: DRIVERS[URI.parse(ENV.fetch(key)).scheme], ok: false, error: e.message }]
    ensure
      db&.close
    end
  end

  def cache
    url = env('REDIS_URL')
    return nil if url.nil?

    redis = Redis.new(url: url, connect_timeout: 5, timeout: 5, reconnect_attempts: 0)
    { driver: 'redis', ok: redis.ping == 'PONG', hits: redis.incr('verify:hits') }
  rescue StandardError => e
    { driver: 'redis', ok: false, error: e.message }
  ensure
    redis&.close
  end

  def report
    marker = File.join(__dir__, 'build-marker')
    secret = env('VERIFY_SECRET')
    {
      fixture: 'ruby',
      marker: env('VERIFY_MARKER'),
      build: File.exist?(marker) ? File.read(marker).strip.then { |text| text.empty? ? nil : text } : nil,
      secret_sha: secret && Digest::SHA1.hexdigest(secret),
      db: database,
      cache: cache,
      extra: extra,
    }
  end
end
