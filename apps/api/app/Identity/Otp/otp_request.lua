local time = redis.call('TIME')
local now_ms = tonumber(time[1]) * 1000 + math.floor(tonumber(time[2]) / 1000)
local window_ms = tonumber(ARGV[8])
local cutoff = now_ms - window_ms
local ip_limit = tonumber(ARGV[6])
local phone_limit = tonumber(ARGV[7])
local key_ttl = ARGV[9]
local challenge_ttl = tonumber(ARGV[10])
local cooldown_ttl = tonumber(ARGV[11])

local attempt_key = 'mf:otp:v1:attempt:' .. ARGV[4]
local send_key = 'mf:otp:v1:send:' .. ARGV[3]
local cool_key = 'mf:otp:v1:cool:' .. ARGV[3]
local active_key = 'mf:otp:v1:active:' .. ARGV[3]
local challenge_key = 'mf:otp:v1:ch:' .. ARGV[2]

local function retry_after(zkey)
    local oldest = redis.call('ZRANGE', zkey, 0, 0, 'WITHSCORES')
    if oldest[2] == nil then
        return 1
    end
    local remain = math.ceil((window_ms - (now_ms - tonumber(oldest[2]))) / 1000)
    if remain < 1 then
        return 1
    end
    return remain
end

local function bound(zkey, limit)
    local card = redis.call('ZCARD', zkey)
    if card > limit then
        redis.call('ZREMRANGEBYRANK', zkey, 0, card - limit - 1)
    end
end

redis.call('ZREMRANGEBYSCORE', attempt_key, '-inf', cutoff)
if redis.call('ZCARD', attempt_key) >= ip_limit then
    redis.call('EXPIRE', attempt_key, key_ttl)
    return {'ip_limited', retry_after(attempt_key)}
end

redis.call('ZADD', attempt_key, now_ms, ARGV[1])
redis.call('EXPIRE', attempt_key, key_ttl)
bound(attempt_key, ip_limit)

redis.call('ZREMRANGEBYSCORE', send_key, '-inf', cutoff)
if redis.call('ZCARD', send_key) >= phone_limit then
    redis.call('EXPIRE', send_key, key_ttl)
    return {'phone_limited', retry_after(send_key)}
end

if redis.call('EXISTS', cool_key) == 1 then
    local ttl = redis.call('PTTL', cool_key)
    local seconds = 1
    if ttl > 0 then
        seconds = math.ceil(ttl / 1000)
    end
    return {'cooldown', seconds}
end

local previous = redis.call('GET', active_key)
if previous and previous ~= ARGV[2] then
    redis.call('DEL', 'mf:otp:v1:ch:' .. previous)
end

local expires_at = tonumber(time[1]) + challenge_ttl
redis.call('HSET', challenge_key, 'phone_hmac', ARGV[3], 'otp_hmac', ARGV[5], 'expires_at', expires_at, 'attempts', '0')
redis.call('EXPIRE', challenge_key, challenge_ttl)
redis.call('SET', active_key, ARGV[2], 'EX', challenge_ttl)
redis.call('SET', cool_key, '1', 'EX', cooldown_ttl)
redis.call('ZADD', send_key, now_ms, ARGV[1])
redis.call('EXPIRE', send_key, key_ttl)
bound(send_key, phone_limit)

return {'ok', ARGV[2], challenge_ttl, cooldown_ttl}
