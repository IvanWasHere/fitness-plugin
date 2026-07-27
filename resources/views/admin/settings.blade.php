{{--
  The wp-admin settings screen.

  Deliberately plain WordPress admin markup — `.wrap`, `.form-table`, `.widefat`
  — rather than anything of our own. An administrator recognises these; a bespoke
  admin design in a plugin whose real UI is elsewhere is effort spent making a
  settings page look unfamiliar.

  Variables: $appBase, $appUrl, $homeUrl, $accounts, $notice, $nonceAction,
  $roleLabels.
--}}
<div class="wrap">
  <h1>{{ __('FitnessClub', 'fitnessclub') }}</h1>

  @if ($notice)
    <div class="notice notice-{{ $notice['type'] === 'error' ? 'error' : ($notice['type'] === 'info' ? 'info' : 'success') }} is-dismissible">
      <p>{{ $notice['message'] }}</p>

      @if (!empty($notice['password']))
        {{-- Shown once, on the way back from the redirect. The transient that
             carried it here has already been deleted. --}}
        <p>
          <strong>{{ $notice['login'] }}</strong> &nbsp;
          <code style="font-size:14px">{{ $notice['password'] }}</code>
        </p>
        <p><em>{{ __('Copy it now — refreshing this page loses it.', 'fitnessclub') }}</em></p>
      @endif
    </div>
  @endif

  <h2>{{ __('App address', 'fitnessclub') }}</h2>

  <p class="description" style="max-width:40rem">
    {{ __('The member, trainer and admin apps are all served from this one address. Which one a visitor gets is decided by the role on their FitnessClub account.', 'fitnessclub') }}
  </p>

  <form method="post" action="{{ admin_url('admin.php?page=fitnessclub') }}">
    {!! wp_nonce_field($nonceAction, '_wpnonce', true, false) !!}
    <input type="hidden" name="fc_action" value="save_routing">

    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">
          <label for="fc-app-base">{{ __('App URL', 'fitnessclub') }}</label>
        </th>
        <td>
          <span style="color:#646970">{{ $homeUrl }}</span><!--
       --><input type="text"
                 id="fc-app-base"
                 name="app_base"
                 value="{{ $appBase }}"
                 class="regular-text"
                 style="width:12rem"
                 required><!--
       --><span style="color:#646970">/</span>

          <p class="description">
            {{ __('Letters, numbers and hyphens. Changing this moves the app and refreshes the site\'s permalinks.', 'fitnessclub') }}
          </p>
          <p>
            <a href="{{ $appUrl }}" target="_blank" rel="noopener">{{ $appUrl }}</a>
          </p>
        </td>
      </tr>
    </table>

    <p class="submit">
      <button type="submit" class="button button-primary">{{ __('Save', 'fitnessclub') }}</button>
    </p>
  </form>

  <hr>

  <h2>{{ __('Generated accounts', 'fitnessclub') }}</h2>

  <p class="description" style="max-width:40rem">
    {{ __('FitnessClub has its own accounts — a WordPress login does not work in the app, and this list is not WordPress users. These were created on activation so that somebody could sign in at all.', 'fitnessclub') }}
  </p>

  @if (empty($accounts))
    <p>
      <em>{{ __('None left. Every generated account has been deleted.', 'fitnessclub') }}</em>
    </p>
    <p class="description">
      {{ __('To create another, run: wp fitnessclub account create --login=name --role=admin', 'fitnessclub') }}
    </p>
  @else
    <table class="widefat striped" style="max-width:64rem">
      <thead>
        <tr>
          <th>{{ __('Account', 'fitnessclub') }}</th>
          <th>{{ __('Login', 'fitnessclub') }}</th>
          <th>{{ __('Role', 'fitnessclub') }}</th>
          <th>{{ __('Status', 'fitnessclub') }}</th>
          <th>{{ __('Last sign-in', 'fitnessclub') }}</th>
          <th>{{ __('Actions', 'fitnessclub') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($accounts as $account)
          <tr>
            <td>{{ $account['display_name'] }}</td>
            <td><code>{{ $account['login'] }}</code></td>
            <td>{{ $roleLabels[$account['role']] ?? $account['role'] }}</td>
            <td>
              @if ($account['status'] === 'active')
                {{ __('Active', 'fitnessclub') }}
              @else
                <strong>{{ $account['status'] }}</strong>
              @endif
            </td>
            <td>
              {{ $account['last_login_at'] ? $account['last_login_at'] : __('Never', 'fitnessclub') }}
            </td>
            <td>
              <form method="post"
                    action="{{ admin_url('admin.php?page=fitnessclub') }}"
                    style="display:inline">
                {!! wp_nonce_field($nonceAction, '_wpnonce', true, false) !!}
                <input type="hidden" name="fc_action" value="reset_password">
                <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                <button type="submit" class="button button-small">
                  {{ __('New password', 'fitnessclub') }}
                </button>
              </form>

              <form method="post"
                    action="{{ admin_url('admin.php?page=fitnessclub') }}"
                    style="display:inline"
                    onsubmit="return confirm('{{ __('Delete this account permanently?', 'fitnessclub') }}')">
                {!! wp_nonce_field($nonceAction, '_wpnonce', true, false) !!}
                <input type="hidden" name="fc_action" value="delete_account">
                <input type="hidden" name="account_id" value="{{ $account['id'] }}">
                <button type="submit" class="button button-small button-link-delete">
                  {{ __('Delete', 'fitnessclub') }}
                </button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <p class="description" style="max-width:40rem; margin-top:1rem">
      {{ __('Delete the example accounts once you have your own — they disappear from this list as soon as their row is gone from the database. The last administrator account cannot be deleted here: there is no WordPress account to fall back on.', 'fitnessclub') }}
    </p>
  @endif
</div>
