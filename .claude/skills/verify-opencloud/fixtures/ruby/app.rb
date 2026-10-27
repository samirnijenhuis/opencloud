require 'json'
require 'sinatra/base'
require_relative 'verify'

class App < Sinatra::Base
  # Outside production, Sinatra answers only local host names. Opencloud serves the app on its own domains.
  set :host_authorization, { permitted_hosts: [] }

  PAGE = <<~HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>opencloud verify: ruby</title>
    </head>
    <body>
        <h1>opencloud verify: ruby</h1>
        <p><a href="/verify">/verify</a></p>
    </body>
    </html>
  HTML

  get '/' do
    PAGE
  end

  get '/verify' do
    content_type :json
    JSON.generate(Verify.report)
  end
end
