-- DRAWBRIDGE's request guard, inside Apache (mod_lua).
--
-- The rules are not written here. They are data in policy.lua, generated from the PHP HeaderPolicy and the
-- app config by `drawbridge render`, so there is one source and it is unit-tested. This file is the loop.
--
--   guard  : first thing, every request. Refuses unsafe paths, drops every header the gateway owns, and
--            reduces Connection to harmless tokens (a Connection token that names a header makes the proxy
--            drop that header, which would let a client delete the assertion the gateway adds).
--   stamp  : after mod_auth_openidc has authenticated, only where a session is required. Checks the
--            authentication context when the path needs one, then builds the signing request for the PHP
--            signer, or plain headers for apps in header mode.
--   scrub  : last stop before the proxy. The session and state cookies are bearer secrets and never leave.
--   up     : the health check.

local run_dir = os.getenv("DRAWBRIDGE_RUN_DIR") or "/tmp/drawbridge"
local policy = dofile(run_dir .. "/generated/policy.lua")

local SAFE_CONNECTION = { ["close"] = true, ["keep-alive"] = true, ["upgrade"] = true }

local function trim(value)
  return (value:gsub("^%s+", ""):gsub("%s+$", ""))
end


-- One decode, the same rules as PathNormalizer in PHP.
local function path_is_safe(raw)
  local path = raw:match("^[^?#]*")
  if path:sub(1, 1) ~= "/" then
    return false
  end
  local lowered = path:lower()
  if lowered:find("%%2f") or lowered:find("%%5c") then
    return false
  end
  local decoded = path:gsub("%%(%x%x)", function(h) return string.char(tonumber(h, 16)) end)
  if decoded:find("[%c;\\]") then
    return false
  end
  return true
end

function guard(r)
  if not path_is_safe(r.unparsed_uri or "") then
    return 400
  end

  -- Apache's header table cannot be listed from Lua, so the generated list names every spelling to remove.
  -- Header names are case-insensitive in the table, so one lower-case spelling covers every capitalisation.
  local headers = r.headers_in
  for _, name in ipairs(policy.strip) do
    if headers[name] ~= nil then
      headers[name] = nil
    end
  end

  local connection = r.headers_in["Connection"]
  if connection then
    local kept, seen = {}, {}
    for token in connection:gmatch("[^,]+") do
      token = trim(token:lower())
      if SAFE_CONNECTION[token] and not seen[token] then
        seen[token] = true
        kept[#kept + 1] = token
      end
    end
    if #kept == 0 then
      r.headers_in["Connection"] = nil
    else
      r.headers_in["Connection"] = table.concat(kept, ", ")
    end
  end

  return apache2.DECLINED
end

local function clean(value)
  return ((value or ""):gsub("%c", ""))
end

-- Whether a claim mod_auth_openidc flattened into the environment (arrays joined with ",") lists a value.
-- Membership, not equality: Entra's "acrs" can name several contexts at once.
local function lists(flat, wanted)
  for item in (flat or ""):gmatch("[^,]+") do
    if trim(item) == wanted then
      return true
    end
  end
  return false
end

-- Require drawbridge-stamp [context]
function stamp(r, context)
  if r.user == nil or r.user == "" then
    return apache2.AUTHZ_DENIED_NO_USER
  end

  local host = (r.hostname or ""):lower()
  local app = policy.apps[host]
  if not app then
    return apache2.AUTHZ_DENIED
  end

  local env = r.subprocess_env

  -- The module's "Require claim" already asked for this; asking again here means one lost line of config
  -- cannot quietly drop the step-up.
  if context ~= nil and context ~= "" then
    if not lists(env[policy.claim_prefix .. "acrs"], context) then
      return apache2.AUTHZ_DENIED
    end
  end

  local subject = clean(r.user)
  if subject == "" then
    r:err("drawbridge: a signed-in request has no subject")
    return apache2.AUTHZ_DENIED
  end

  if app.mode ~= "headers" then
    -- The validated ID token payload (OIDCPassIDTokenAs payload), JSON. The PHP signer reads the claims out of it.
    local payload = env["OIDC_id_token_payload"] or ""
    if payload == "" then
      r:err("drawbridge: a signed-in request has no ID token payload")
      return apache2.AUTHZ_DENIED
    end
    env["DRAWBRIDGE_SIGN_KEY"] = "v2." .. r:base64_encode(host) .. "." .. r:base64_encode(payload)
  end

  if app.mode ~= "assertion" then
    r.headers_in[policy.user_header] = subject
    for attribute, header in pairs(policy.attribute_headers) do
      local claim = policy.attribute_claims[attribute]
      if claim then
        local value = clean(env[policy.claim_prefix .. claim])
        if value ~= "" then
          r.headers_in[header] = value
        end
      end
    end
  end

  return apache2.AUTHZ_GRANTED
end

local function has_prefix(name, prefixes)
  for _, prefix in ipairs(prefixes) do
    if name:sub(1, #prefix) == prefix then
      return true
    end
  end
  return false
end

function scrub(r)
  -- The gateway's own endpoint (the OpenID Connect redirect URI, and logout) needs the cookies; only proxied requests lose them.
  if r.uri == "/_drawbridge" or r.uri:sub(1, 13) == "/_drawbridge/" then
    return apache2.DECLINED
  end

  local cookie = r.headers_in["Cookie"]
  if not cookie then
    return apache2.DECLINED
  end
  local kept = {}
  for pair in cookie:gmatch("[^;]+") do
    local name = pair:match("^%s*([^=%s]+)")
    if name and not policy.cookies[name] and not has_prefix(name, policy.cookie_prefixes) then
      kept[#kept + 1] = (pair:gsub("^%s+", ""))
    end
  end
  if #kept == 0 then
    r.headers_in["Cookie"] = nil
  else
    r.headers_in["Cookie"] = table.concat(kept, "; ")
  end
  return apache2.DECLINED
end

function up(r)
  r.content_type = "text/plain"
  r:puts("ok\n")
  return apache2.OK
end
