// ================================================================
// LAE Moderation Bot
// Slash commands: /ban /kick /warn /note /info
//                 /removewarn /removenote /removeban
// ================================================================
require('dotenv').config();

const { Client, GatewayIntentBits, EmbedBuilder, Colors } = require('discord.js');
const mysql = require('mysql2/promise');

// ── Config ─────────────────────────────────────────────────────
const BOT_TOKEN       = process.env.BOT_TOKEN;
const GUILD_ID        = process.env.GUILD_ID;
const MOD_LOG_CHANNEL = process.env.MOD_LOG_CHANNEL;
const MOD_ROLE_IDS    = (process.env.MOD_ROLE_IDS || '').split(',').map(s => s.trim()).filter(Boolean);
const PREFIX          = process.env.INFRACTION_PREFIX || 'LAE';
const FORUM_URL       = process.env.FORUM_URL || 'https://laexperiencefivem.com';

if (!BOT_TOKEN) { console.error('BOT_TOKEN missing in .env'); process.exit(1); }

// ── DB Pool ─────────────────────────────────────────────────────
const pool = mysql.createPool({
  host:            process.env.DB_HOST || 'localhost',
  port:            parseInt(process.env.DB_PORT || '3306'),
  user:            process.env.DB_USER || 'root',
  password:        process.env.DB_PASS || '',
  database:        process.env.DB_NAME || 'lae_forum',
  waitForConnections: true,
  connectionLimit: 5,
});

// ── Helpers ─────────────────────────────────────────────────────
async function nextInfractionId() {
  const [rows] = await pool.query('UPDATE infraction_seq SET id = LAST_INSERT_ID(id + 1)');
  const [seq]  = await pool.query('SELECT LAST_INSERT_ID() AS n');
  const n      = seq[0].n;
  return `${PREFIX}-${String(n).padStart(4, '0')}`;
}

function parseDuration(dur) {
  if (!dur) return null;
  dur = dur.toLowerCase().trim();
  if (dur === 'perm' || dur === 'permanent') return null;
  const map = { h: 'hours', d: 'days', w: 'weeks', m: 'months' };
  const match = dur.match(/^(\d+)([hdwm])$/);
  if (!match) return null;
  const ms = { h: 3600, d: 86400, w: 604800, m: 2592000 }[match[2]] * parseInt(match[1]) * 1000;
  return new Date(Date.now() + ms);
}

function formatDur(dur) {
  if (!dur) return 'Permanent';
  return dur;
}

function hasModRole(member) {
  if (MOD_ROLE_IDS.length === 0) return true; // no restriction set
  return member.roles.cache.some(r => MOD_ROLE_IDS.includes(r.id));
}

// Professional color scheme (no emojis)
const TYPE_COLOR = { ban: 0xDC2626, kick: 0xD97706, warn: 0xEAB308, note: 0x2563EB };
const TYPE_LABEL = { ban: 'BAN', kick: 'KICK', warn: 'WARNING', note: 'STAFF NOTE' };
const TYPE_REMOVE_COLOR = 0x22C55E; // Green for removals

async function createInfraction(type, playerName, reason, identifier, discordId, duration, staffName, staffId, note) {
  const infrId   = await nextInfractionId();
  const expiresAt = duration ? parseDuration(duration) : null;
  const [result] = await pool.query(
    `INSERT INTO infractions
       (infraction_id, type, player_name, player_identifier, player_discord_id,
        reason, duration, expires_at, issued_by_name, issued_via, is_active, notes)
     VALUES (?,?,?,?,?,?,?,?,?,'discord',1,?)`,
    [infrId, type, playerName, identifier||null, discordId||null,
     reason, duration||null, expiresAt||null, staffName, note||null]
  );
  return { id: result.insertId, infraction_id: infrId, type, player_name: playerName,
           reason, duration, expires_at: expiresAt, issued_by_name: staffName,
           player_identifier: identifier, notes: note };
}

async function buildLogEmbed(infr, staffMention) {
  const label = TYPE_LABEL[infr.type] || infr.type.toUpperCase();
  const color = TYPE_COLOR[infr.type] || 0x6B7280;

  // Build clean description
  let desc = `**Player:** ${infr.player_name}\n`;
  desc += `**Case ID:** \`${infr.infraction_id}\`\n`;
  if (infr.player_identifier) {
    desc += `**Identifier:** \`${infr.player_identifier}\`\n`;
  }
  desc += `\n**Reason**\n${infr.reason}`;

  const embed = new EmbedBuilder()
    .setAuthor({ name: 'LAE Moderation' })
    .setTitle(`${label} | ${infr.player_name}`)
    .setDescription(desc)
    .setColor(color)
    .addFields(
      { name: 'Issued By', value: staffMention, inline: true },
      { name: 'Source', value: 'Discord', inline: true },
    )
    .setFooter({ text: 'Los Angeles Experience' })
    .setTimestamp();

  if (infr.type === 'ban') {
    embed.addFields({ 
      name: 'Expires', 
      value: infr.expires_at ? `<t:${Math.floor(infr.expires_at.getTime()/1000)}:F>` : 'Permanent', 
      inline: true 
    });
  }
  if (infr.notes) {
    embed.addFields({ name: 'Staff Notes', value: infr.notes, inline: false });
  }

  return embed;
}

async function postLog(client, embed) {
  if (!MOD_LOG_CHANNEL) return null;
  try {
    const ch = await client.channels.fetch(MOD_LOG_CHANNEL);
    const msg = await ch.send({ embeds: [embed] });
    return msg.id;
  } catch(e) { console.error('[ModBot] Log channel error:', e.message); return null; }
}

async function removeInfraction(code, removedBy, removeReason) {
  code = code.toUpperCase();
  const [rows] = await pool.query(
    'UPDATE infractions SET is_active=0, removed_by=?, removed_at=NOW(), remove_reason=? WHERE infraction_id=? AND is_active=1',
    [removedBy, removeReason||null, code]
  );
  return rows.affectedRows > 0;
}

async function getPlayerHistory(search) {
  const [rows] = await pool.query(
    `SELECT * FROM infractions
     WHERE LOWER(player_name)=LOWER(?) OR player_identifier=? OR player_discord_id=?
     ORDER BY created_at DESC`,
    [search, search, search]
  );
  return rows;
}

// ── Bot client ──────────────────────────────────────────────────
const client = new Client({ intents: [GatewayIntentBits.Guilds] });

client.once('ready', () => {
  console.log(`[LAE ModBot] Logged in as ${client.user.tag}`);
  client.user.setPresence({ status: 'online', activities: [{ name: 'LAE Moderation', type: 3 }] });
});

client.on('interactionCreate', async interaction => {
  if (!interaction.isChatInputCommand()) return;
  const cmd = interaction.commandName;

  // Permission check
  if (!hasModRole(interaction.member)) {
    return interaction.reply({ content: '❌ You do not have permission to use moderation commands.', ephemeral: true });
  }

  const staffName    = interaction.user.username;
  const staffMention = `<@${interaction.user.id}>`;

  try {
    // ── /ban ──────────────────────────────────────────────────────
    if (cmd === 'ban') {
      const player     = interaction.options.getString('player');
      const reason     = interaction.options.getString('reason');
      const duration   = interaction.options.getString('duration') || null;
      const identifier = interaction.options.getString('identifier');
      const discordId  = interaction.options.getString('discord_id');
      const note       = interaction.options.getString('note');

      await interaction.deferReply({ ephemeral: true });
      const infr = await createInfraction('ban', player, reason, identifier, discordId, duration, staffName, interaction.user.id, note);
      const embed = await buildLogEmbed(infr, staffMention);
      const msgId = await postLog(client, embed);
      if (msgId) await pool.query('UPDATE infractions SET discord_msg_id=? WHERE id=?', [msgId, infr.id]);

      await interaction.editReply({
        content: `✅ **BAN issued**\n**Case:** \`${infr.infraction_id}\`\n**Player:** ${player}\n**Duration:** ${duration ? formatDur(duration) : 'Permanent'}\n**Reason:** ${reason}`
      });
    }

    // ── /kick ─────────────────────────────────────────────────────
    else if (cmd === 'kick') {
      const player     = interaction.options.getString('player');
      const reason     = interaction.options.getString('reason');
      const identifier = interaction.options.getString('identifier');
      const note       = interaction.options.getString('note');

      await interaction.deferReply({ ephemeral: true });
      const infr = await createInfraction('kick', player, reason, identifier, null, null, staffName, interaction.user.id, note);
      const embed = await buildLogEmbed(infr, staffMention);
      const msgId = await postLog(client, embed);
      if (msgId) await pool.query('UPDATE infractions SET discord_msg_id=? WHERE id=?', [msgId, infr.id]);

      await interaction.editReply({ content: `✅ **KICK issued** — \`${infr.infraction_id}\`\n**Player:** ${player}\n**Reason:** ${reason}` });
    }

    // ── /warn ─────────────────────────────────────────────────────
    else if (cmd === 'warn') {
      const player     = interaction.options.getString('player');
      const reason     = interaction.options.getString('reason');
      const identifier = interaction.options.getString('identifier');
      const note       = interaction.options.getString('note');

      await interaction.deferReply({ ephemeral: true });
      const infr = await createInfraction('warn', player, reason, identifier, null, null, staffName, interaction.user.id, note);

      // Check warning count — alert if 3+ active warns
      const [warns] = await pool.query(
        "SELECT COUNT(*) AS n FROM infractions WHERE LOWER(player_name)=LOWER(?) AND type='warn' AND is_active=1",
        [player]
      );
      const warnCount = warns[0].n;

      const embed = await buildLogEmbed(infr, staffMention);
      if (warnCount >= 3) {
        embed.addFields({ name: '🚨 Escalation Alert', value: `This player now has **${warnCount} active warnings**. Per server rules, 4 warnings = permanent ban.`, inline: false });
        embed.setColor(0xE63946);
      }
      const msgId = await postLog(client, embed);
      if (msgId) await pool.query('UPDATE infractions SET discord_msg_id=? WHERE id=?', [msgId, infr.id]);

      await interaction.editReply({
        content: `✅ **WARNING issued** — \`${infr.infraction_id}\`\n**Player:** ${player}\n**Reason:** ${reason}\n**Total active warnings:** ${warnCount}`
      });
    }

    // ── /note ─────────────────────────────────────────────────────
    else if (cmd === 'note') {
      const player     = interaction.options.getString('player');
      const content    = interaction.options.getString('content');
      const identifier = interaction.options.getString('identifier');

      await interaction.deferReply({ ephemeral: true });
      const infr = await createInfraction('note', player, content, identifier, null, null, staffName, interaction.user.id, null);
      const embed = await buildLogEmbed(infr, staffMention);
      const msgId = await postLog(client, embed);
      if (msgId) await pool.query('UPDATE infractions SET discord_msg_id=? WHERE id=?', [msgId, infr.id]);

      await interaction.editReply({ content: `✅ **NOTE added** — \`${infr.infraction_id}\`\n**Player:** ${player}` });
    }

    // ── /info ─────────────────────────────────────────────────────
    else if (cmd === 'info') {
      const search = interaction.options.getString('player');
      await interaction.deferReply({ ephemeral: false }); // public — visible to staff in channel

      const records = await getPlayerHistory(search);

      if (records.length === 0) {
        return interaction.editReply({ content: `✅ No infraction records found for **${search}**.` });
      }

      const active   = records.filter(r => r.is_active);
      const bans     = active.filter(r => r.type === 'ban').length;
      const warns    = active.filter(r => r.type === 'warn').length;
      const kicks    = records.filter(r => r.type === 'kick').length;
      const notes    = active.filter(r => r.type === 'note').length;

      const embed = new EmbedBuilder()
        .setAuthor({ name: 'LAE Moderation' })
        .setTitle(`Player History | ${search}`)
        .setColor(bans > 0 ? 0xDC2626 : warns >= 3 ? 0xEAB308 : 0x2563EB)
        .setDescription(
          `**Active Bans:** ${bans}  |  **Active Warns:** ${warns}  |  **Kicks:** ${kicks}  |  **Notes:** ${notes}\n` +
          `**Total Cases:** ${records.length}`
        )
        .setFooter({ text: 'Los Angeles Experience' })
        .setTimestamp();

      // Show up to 10 most recent
      const shown = records.slice(0, 10);
      for (const r of shown) {
        const label  = TYPE_LABEL[r.type] || r.type.toUpperCase();
        const status = r.is_active ? 'Active' : 'Removed';
        const date   = new Date(r.created_at).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
        const val    = [
          `**Reason:** ${r.reason}`,
          `**By:** ${r.issued_by_name} via ${r.issued_via} | ${date}`,
          r.type === 'ban' ? `**Expires:** ${r.expires_at ? new Date(r.expires_at).toLocaleDateString('en-GB') : 'Permanent'}` : '',
          !r.is_active ? `**Removed by:** ${r.removed_by || 'System'}${r.remove_reason ? ` — ${r.remove_reason}` : ''}` : '',
        ].filter(Boolean).join('\n');
        embed.addFields({ name: `[${status}] ${label} — \`${r.infraction_id}\``, value: val, inline: false });
      }
      if (records.length > 10) embed.addFields({ name: 'Additional Records', value: `+${records.length - 10} more records. [View all on website](${FORUM_URL}/admin/moderation.php?search=${encodeURIComponent(search)})`, inline: false });

      await interaction.editReply({ embeds: [embed] });
    }

    // ── /removewarn / /removenote / /removeban ────────────────────
    else if (['removewarn','removenote','removeban'].includes(cmd)) {
      const code   = interaction.options.getString('id').toUpperCase();
      const reason = interaction.options.getString('reason') || '';

      await interaction.deferReply({ ephemeral: true });
      const ok = await removeInfraction(code, staffName, reason);

      if (!ok) {
        return interaction.editReply({ content: `❌ No active infraction found with ID \`${code}\`.` });
      }

      // Post removal notice to log channel
      const embed = new EmbedBuilder()
        .setAuthor({ name: 'LAE Moderation' })
        .setTitle(`INFRACTION REMOVED | ${code}`)
        .setDescription(`Case \`${code}\` has been deactivated and is no longer enforced.`)
        .setColor(TYPE_REMOVE_COLOR)
        .addFields(
          { name: 'Removed By', value: staffMention, inline: true },
          { name: 'Case ID', value: `\`${code}\``, inline: true },
          { name: 'Reason', value: reason || 'No reason provided', inline: false },
        )
        .setFooter({ text: 'Los Angeles Experience' })
        .setTimestamp();
      await postLog(client, embed);

      await interaction.editReply({ content: `✅ Infraction \`${code}\` has been removed.\n**Reason:** ${reason || 'None given'}` });
    }

  } catch (err) {
    console.error(`[ModBot] Error in /${cmd}:`, err);
    const msg = { content: '❌ An internal error occurred. Please try again or use the website panel.', ephemeral: true };
    if (interaction.deferred) await interaction.editReply(msg);
    else await interaction.reply(msg);
  }
});

// ── Error handling ───────────────────────────────────────────────
client.on('error', err => console.error('[ModBot] Client error:', err));
process.on('unhandledRejection', err => console.error('[ModBot] Unhandled rejection:', err));
process.on('SIGTERM', () => { client.destroy(); process.exit(0); });
process.on('SIGINT',  () => { client.destroy(); process.exit(0); });

console.log('[LAE ModBot] Connecting...');
client.login(BOT_TOKEN).catch(err => { console.error('Login failed:', err); process.exit(1); });
