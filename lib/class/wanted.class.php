<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2014 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

use MusicBrainz\MusicBrainz;
use MusicBrainz\Clients\RequestsMbClient;

class Wanted extends database_object
{
    /* Variables from DB */
    public $id;
    public $mbid;
    public $artist;
    public $artist_mbid;
    public $name;
    public $year;
    public $accepted;
    public $release_mbid;
    public $user;

    public $f_name_link;
    public $f_artist_link;
    public $f_user;
    public $songs;

    /**
     * Constructor
     */
    public function __construct($id=0)
    {
        if (!$id) { return true; }

        /* Get the information from the db */
        $info = $this->get_info($id);

        // Foreach what we've got
        foreach ($info as $key=>$value) {
            $this->$key = $value;
        }

        return true;
    } //constructor

    /**
     * get_missing_albums
     * Get list of library's missing albums from MusicBrainz
     */
    public static function get_missing_albums($artist, $mbid='')
    {
        $mb = new MusicBrainz(new RequestsMbClient());
        $includes = array(
            'release-groups'
        );
        $types = explode(',', AmpConfig::get('wanted_types'));

        try {
            $martist = $mb->lookup('artist', $artist ? $artist->mbid : $mbid, $includes);
        } catch (Exception $e) {
            return null;
        }

        $owngroups = array();
        $wartist = array();
        if ($artist) {
            $albums = $artist->get_albums();
            foreach ($albums as $id) {
                $album = new Album($id);
                if ($album->mbid) {
                    $malbum = $mb->lookup('release', $album->mbid, array('release-groups'));
                    if ($malbum->{'release-group'}) {
                        if (!in_array($malbum->{'release-group'}->id, $owngroups)) {
                            $owngroups[] = $malbum->{'release-group'}->id;
                        }
                    }
                }
            }
        } else {
            $wartist['mbid'] = $mbid;
            $wartist['name'] = $martist->name;
            parent::add_to_cache('missing_artist', $mbid, $wartist);
            $wartist = self::get_missing_artist($mbid);
        }

        $results = array();
        foreach ($martist->{'release-groups'} as $group) {
            if (in_array(strtolower($group->{'primary-type'}), $types)) {
                $add = true;

                for ($i = 0; $i < count($group->{'secondary-types'}) && $add; ++$i) {
                    $add = in_array(strtolower($group->{'secondary-types'}[$i]), $types);
                }

                if ($add) {
                    if (!in_array($group->id, $owngroups)) {
                        $wantedid = self::get_wanted($group->id);
                        $wanted = new Wanted($wantedid);
                        if ($wanted->id) {
                            $wanted->format();
                        } else {
                            $wanted->mbid = $group->id;
                            if ($artist) {
                                $wanted->artist = $artist->id;
                            } else {
                                $wanted->artist_mbid = $mbid;
                            }
                            $wanted->name = $group->title;
                            if (!empty($group->{'first-release-date'})) {
                                if (strlen($group->{'first-release-date'}) == 4) {
                                    $wanted->year = $group->{'first-release-date'};
                                } else {
                                    $wanted->year = date("Y", strtotime($group->{'first-release-date'}));
                                }
                            }
                            $wanted->accepted = false;
                            $wanted->f_name_link = "<a href=\"" . AmpConfig::get('web_path') . "/albums.php?action=show_missing&mbid=" . $group->id;
                            if ($artist) {
                                $wanted->f_name_link .= "&artist=" . $wanted->artist;
                            } else {
                                $wanted->f_name_link .= "&artist_mbid=" . $mbid;
                            }
                            $wanted->f_name_link .= "\" title=\"" . $wanted->name . "\">" . $wanted->name . "</a>";
                            $wanted->f_artist_link = $artist ? $artist->f_name_link : $wartist['link'];
                            $wanted->f_user = $GLOBALS['user']->fullname;
                        }
                        $results[] = $wanted;
                    }
                }
            }
        }

        return $results;
    } // get_missing_albums

    public static function get_missing_artist($mbid)
    {
        $wartist = array();

        if (parent::is_cached('missing_artist', $mbid) ) {
            $wartist = parent::get_from_cache('missing_artist', $mbid);
        } else {
            $mb = new MusicBrainz(new RequestsMbClient());
            $wartist['mbid'] = $mbid;
            $wartist['name'] = T_('Unknown Artist');

            try {
                $martist = $mb->lookup('artist', $mbid);
            } catch (Exception $e) {
                return $wartist;
            }

            $wartist['name'] = $martist->name;
            parent::add_to_cache('missing_artist', $mbid, $wartist);
        }

        $wartist['link'] = "<a href=\"" . AmpConfig::get('web_path') . "/artists.php?action=show_missing&mbid=" . $wartist['mbid'] . "\" title=\"" . $wartist['name'] . "\">" . $wartist['name'] . "</a>";

        return $wartist;
    }

    public static function get_accepted_wanted_count()
    {
        $sql = "SELECT COUNT(`id`) AS `wanted_cnt` FROM `wanted` WHERE `accepted` = 1";
        $db_results = Dba::read($sql);
        if ($row = Dba::fetch_assoc($db_results)) {
            return $row['wanted_cnt'];
        }

        return 0;
    }

    public static function get_wanted($mbid)
    {
        $sql = "SELECT `id` FROM `wanted` WHERE `mbid` = ?";
        $db_results = Dba::read($sql, array($mbid));
        if ($row = Dba::fetch_assoc($db_results)) {
            return $row['id'];
        }

        return false;
    }

    public static function delete_wanted($mbid)
    {
        $sql = "DELETE FROM `wanted` WHERE `mbid` = ?";
        $params = array( $mbid );
        if (!$GLOBALS['user']->has_access('75')) {
            $sql .= " AND `user` = ?";
            $params[] = $GLOBALS['user']->id;
        }

        Dba::write($sql, $params);
    }

    public static function delete_wanted_release($mbid)
    {
        if (self::get_accepted_wanted_count() > 0) {
            $mb = new MusicBrainz(new RequestsMbClient());
            $malbum = $mb->lookup('release', $mbid, array('release-groups'));
            if ($malbum->{'release-group'}) {
                self::delete_wanted($malbum->{'release-group'}->id);
            }
        }
    }

    public static function delete_wanted_by_name($artist, $album_name, $year)
    {
        $sql = "DELETE FROM `wanted` WHERE `artist` = ? AND `name` = ? AND `year` = ?";
        $params = array( $artist, $album_name, $year );
        if (!$GLOBALS['user']->has_access('75')) {
            $sql .= " AND `user` = ?";
            $params[] = $GLOBALS['user']->id;
        }

        Dba::write($sql, $params);
    }

    public function accept()
    {
        if ($GLOBALS['user']->has_access('75')) {
            $sql = "UPDATE `wanted` SET `accepted` = '1' WHERE `mbid` = ?";
            Dba::write($sql, array( $this->mbid ));
            $this->accepted = 1;

            foreach (Plugin::get_plugins('process_wanted') as $plugin_name) {
                debug_event('wanted', 'Using Wanted Process plugin: ' . $plugin_name, '5');
                $plugin = new Plugin($plugin_name);
                if ($plugin->load($GLOBALS['user'])) {
                    $plugin->_plugin->process_wanted($this);
                }
            }
        }
    }

    public static function has_wanted($mbid, $userid = 0)
    {
        if ($userid == 0) {
            $userid = $GLOBALS['user']->id;
        }

        $sql = "SELECT `id` FROM `wanted` WHERE `mbid` = ? AND `user` = ?";
        $db_results = Dba::read($sql, array($mbid, $userid));

        if ($row = Dba::fetch_assoc($db_results)) {
            return $row['id'];
        }

        return false;

    }

    public static function add_wanted($mbid, $artist, $artist_mbid, $name, $year)
    {
        $sql = "INSERT INTO `wanted` (`user`, `artist`, `artist_mbid`, `mbid`, `name`, `year`, `date`, `accepted`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $accept = $GLOBALS['user']->has_access('75') ? true : AmpConfig::get('wanted_auto_accept');
        $params = array($GLOBALS['user']->id, $artist, $artist_mbid, $mbid, $name, $year, time(), '0');
        Dba::write($sql, $params);

        if ($accept) {
            $wantedid = Dba::insert_id();
            $wanted = new Wanted($wantedid);
            $wanted->accept();
        }
    }

    public function show_action_buttons()
    {
        if ($this->id) {
            if (!$this->accepted) {
                if ($GLOBALS['user']->has_access('75')) {
                    echo Ajax::button('?page=index&action=accept_wanted&mbid=' . $this->mbid,'enable', T_('Accept'),'wanted_accept_' . $this->mbid);
                }
            }
            if ($GLOBALS['user']->has_access('75') || (Wanted::has_wanted($this->mbid) && $this->accepted != '1')) {
                echo " " . Ajax::button('?page=index&action=remove_wanted&mbid=' . $this->mbid,'disable', T_('Remove'),'wanted_remove_' . $this->mbid);
            }
        } else {
            echo Ajax::button('?page=index&action=add_wanted&mbid=' . $this->mbid . ($this->artist ? '&artist=' . $this->artist : '&artist_mbid=' . $this->artist_mbid) . '&name=' . urlencode($this->name) . '&year=' . $this->year,'add_wanted', T_('Add to wanted list'),'wanted_add_' . $this->mbid);
        }
    }

    public function load_all($track_details = true)
    {
        $mb = new MusicBrainz(new RequestsMbClient());
        $this->songs = array();

        try {
            $group = $mb->lookup('release-group', $this->mbid, array( 'releases' ));
            // Set fresh data
            $this->name = $group->title;
            $this->year = date("Y", strtotime($group->{'first-release-date'}));

            // Load from database if already cached
            $this->songs = Song_preview::get_song_previews($this->mbid);

            if (count($group->releases) > 0) {
                $this->release_mbid = $group->releases[0]->id;
                if ($track_details && count($this->songs) == 0) {
                    // Use the first release as reference for track content
                    $release = $mb->lookup('release', $this->release_mbid, array( 'recordings' ));
                    foreach ($release->media as $media) {
                        foreach ($media->tracks as $track) {
                            $song = array();
                            $song['disk'] = $media->position;
                            $song['track'] = $track->number;
                            $song['title'] = $track->title;
                            $song['mbid'] = $track->id;
                            if ($this->artist) {
                                $song['artist'] = $this->artist;
                            }
                            $song['artist_mbid'] = $this->artist_mbid;
                            $song['session'] = session_id();
                            $song['album_mbid'] = $this->mbid;
                            if (AmpConfig::get('echonest_api_key')) {
                                $echonest = new EchoNest_Client(new EchoNest_HttpClient_Requests());
                                $echonest->authenticate(AmpConfig::get('echonest_api_key'));
                                $enSong = null;
                                try {
                                    $enProfile = $echonest->getTrackApi()->profile('musicbrainz:track:' . $track->id);
                                    $enSong = $echonest->getSongApi()->profile($enProfile['song_id'], array( 'id:7digital-US', 'audio_summary', 'tracks'));
                                } catch (Exception $e) {
                                    debug_event('echonest', 'EchoNest track error on `' . $track->id . '` (' . $track->title . '): ' . $e->getMessage(), '1');
                                }

                                // Wans't able to get the song with MusicBrainz ID, try a search
                                if ($enSong == null) {
                                    if ($this->artist) {
                                        $artist = new Artist($this->artist);
                                        $artist_name = $artist->name;
                                    } else {
                                        $wartist = Wanted::get_missing_artist($this->artist_mbid);
                                        $artist_name = $wartist['name'];
                                    }
                                    try {
                                        $enSong = $echonest->getSongApi()->search(array(
                                            'results' => '1',
                                            'artist' => $artist_name,
                                            'title' => $track->title,
                                            'bucket' => array( 'id:7digital-US', 'audio_summary', 'tracks'),
                                        ));


                                    } catch (Exception $e) {
                                        debug_event('echonest', 'EchoNest song search error: ' . $e->getMessage(), '1');
                                    }
                                }

                                if ($enSong != null) {
                                    $song['file'] = $enSong[0]['tracks'][0]['preview_url'];
                                    debug_event('echonest', 'EchoNest `' . $track->title . '` preview: ' . $song['file'], '1');
                                }
                            }
                            $this->songs[] = new Song_Preview(Song_preview::insert($song));
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->songs = array();
        }

        foreach ($this->songs as $song) {
            $song->f_album = $this->name;
            $song->format();
        }
    }

    public function format()
    {
        if ($this->artist) {
            $artist = new Artist($this->artist);
            $artist->format();
            $this->f_artist_link = $artist->f_name_link;
        } else {
            $wartist = Wanted::get_missing_artist($this->artist_mbid);
            $this->f_artist_link = $wartist['link'];
        }
        $this->f_name_link = "<a href=\"" . AmpConfig::get('web_path') . "/albums.php?action=show_missing&mbid=" . $this->mbid . "&artist=" . $this->artist . "&artist_mbid=" . $this->artist_mbid . "\" title=\"" . $this->name . "\">" . $this->name . "</a>";
        $user = new User($this->user);
        $this->f_user = $user->fullname;

    }

    public static function get_wanted_list_sql()
    {
        $sql = "SELECT `id` FROM `wanted` ";

        if (!$GLOBALS['user']->has_access('75')) {
            $sql .= "WHERE `user` = '" . scrub_in($GLOBALS['user']->id) . "'";
        }

        return $sql;
    }

    public static function get_wanted_list()
    {
        $sql = self::get_wanted_list_sql();
        $db_results = Dba::read($sql);
        $results = array();

        while ($row = Dba::fetch_assoc($db_results)) {
            $results[] = $row['id'];
        }

        return $results;
    }

} // end of recommendation class
