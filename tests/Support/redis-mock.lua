-- A redis.call() stand-in for the Lua harness (tests/Support/LuaRedisConnection.php): the
-- sorted-set and string commands RedisStore uses, with Redis's semantics — members unique per
-- set, score ranges with "(" exclusive and -inf/+inf bounds, results ordered by (score,
-- member), GET of a missing key = false, numbers replied as integers. Written in the Lua 5.1
-- subset Redis embeds, so it also runs under lua5.1.
--
-- The PHP side prepends the state (ZSETS, STRINGS, TTLS), KEYS and ARGV, appends the script
-- under test as SCRIPT (a function), and reads back the dump printed at the end.

-- A score bound: Redis parses it with strtod, so "-inf"/"+inf"/"inf" in any case, and a
-- leading "(" makes it exclusive.
local function bound(value)
  local text = string.lower(tostring(value))
  local open = string.sub(text, 1, 1) == '('
  if open then text = string.sub(text, 2) end
  if text == '-inf' then return -math.huge, open end
  if text == '+inf' or text == 'inf' then return math.huge, open end
  local number = tonumber(text)
  if number == nil then error('ERR min or max is not a float') end
  return number, open
end

local function in_range(score, min, min_open, max, max_open)
  if min_open then
    if not (score > min) then return false end
  elseif not (score >= min) then
    return false
  end
  if max_open then
    return score < max
  end
  return score <= max
end

local function sorted(key)
  local set = ZSETS[key] or {}
  local list = {}
  for member, score in pairs(set) do
    table.insert(list, { member = member, score = score })
  end
  table.sort(list, function (a, b)
    if a.score == b.score then return a.member < b.member end
    return a.score < b.score
  end)
  return list
end

local function select(key, min_value, max_value)
  local min, min_open = bound(min_value)
  local max, max_open = bound(max_value)
  local found = {}
  for _, entry in ipairs(sorted(key)) do
    if in_range(entry.score, min, min_open, max, max_open) then
      table.insert(found, entry)
    end
  end
  return found
end

local function format_score(score)
  if score == math.floor(score) then
    return string.format('%d', score)
  end
  return string.format('%.17g', score)
end

local commands = {}

commands.ZADD = function (key, score, member)
  ZSETS[key] = ZSETS[key] or {}
  local fresh = ZSETS[key][tostring(member)] == nil
  ZSETS[key][tostring(member)] = tonumber(score)
  if fresh then return 1 end
  return 0
end

commands.ZCOUNT = function (key, min, max)
  return table.getn(select(key, min, max))
end

commands.ZRANGEBYSCORE = function (key, min, max, ...)
  local options = { ... }
  local withscores, offset, count = false, 0, -1
  local i = 1
  while i <= table.getn(options) do
    local option = string.upper(tostring(options[i]))
    if option == 'WITHSCORES' then
      withscores = true
    elseif option == 'LIMIT' then
      offset, count = tonumber(options[i + 1]), tonumber(options[i + 2])
      i = i + 2
    else
      error('ERR syntax error')
    end
    i = i + 1
  end
  local reply = {}
  local taken = 0
  for index, entry in ipairs(select(key, min, max)) do
    if index > offset and (count < 0 or taken < count) then
      table.insert(reply, entry.member)
      if withscores then table.insert(reply, format_score(entry.score)) end
      taken = taken + 1
    end
  end
  return reply
end

commands.ZREMRANGEBYSCORE = function (key, min, max)
  local removed = 0
  for _, entry in ipairs(select(key, min, max)) do
    ZSETS[key][entry.member] = nil
    removed = removed + 1
  end
  if ZSETS[key] and next(ZSETS[key]) == nil then ZSETS[key] = nil end
  return removed
end

commands.EXPIRE = function (key, seconds)
  if ZSETS[key] == nil and STRINGS[key] == nil then return 0 end
  TTLS[key] = tonumber(seconds)
  return 1
end

commands.GET = function (key)
  if STRINGS[key] == nil then return false end
  return STRINGS[key]
end

commands.SET = function (key, value)
  STRINGS[key] = tostring(value)
  TTLS[key] = nil
  return { ok = 'OK' }
end

commands.DEL = function (key)
  local existed = ZSETS[key] ~= nil or STRINGS[key] ~= nil
  ZSETS[key], STRINGS[key], TTLS[key] = nil, nil, nil
  if existed then return 1 end
  return 0
end

redis = {
  call = function (name, ...)
    local command = commands[string.upper(name)]
    if command == nil then error('ERR unknown command ' .. name) end
    return command(unpack({ ... }))
  end,
}

local function hex(value)
  return (string.gsub(tostring(value), '.', function (c) return string.format('%02x', string.byte(c)) end))
end

local function emit(prefix, value)
  local kind = type(value)
  if kind == 'number' then
    print(prefix .. '\tI\t' .. string.format('%d', value >= 0 and math.floor(value) or math.ceil(value)))
  elseif kind == 'string' then
    print(prefix .. '\tS\t' .. hex(value))
  elseif kind == 'boolean' then
    if value then print(prefix .. '\tI\t1') else print(prefix .. '\tN\t') end
  elseif kind == 'table' then
    if value.ok ~= nil then
      print(prefix .. '\tS\t' .. hex(value.ok))
    else
      print(prefix .. '\tA\t' .. table.getn(value))
      for _, item in ipairs(value) do emit('ITEM', item) end
    end
  else
    print(prefix .. '\tN\t')
  end
end

function DUMP(reply)
  emit('REPLY', reply)
  for key, set in pairs(ZSETS) do
    for member, score in pairs(set) do
      print('Z\t' .. hex(key) .. '\t' .. hex(member) .. '\t' .. format_score(score))
    end
  end
  for key, value in pairs(STRINGS) do print('S\t' .. hex(key) .. '\t' .. hex(value)) end
  for key, ttl in pairs(TTLS) do print('T\t' .. hex(key) .. '\t' .. ttl) end
end
