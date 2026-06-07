@extends('layouts/basic')


{{-- Page content --}}
@section('content')

    {{-- Whether to render the local username/password form. The controller only
         sets this true when SAML isn't the required path, or for the ?nosaml
         super-admin bypass. Default defensively for any other render path. --}}
    @php $showLocalLogin = $showLocalLogin ?? ! config('app.require_saml'); @endphp

    <style nonce="{{ csrf_token() }}">
        body.login-page {
            background: linear-gradient(165deg, #eef2f7 0%, #d9e2ee 100%);
            min-height: 100vh;
        }
        #login-logo { margin-top: 7vh; margin-bottom: 6px; }
        .login-box {
            width: 408px;
            margin: 14px auto 0;
            border: none !important;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 14px 44px rgba(16, 24, 40, .14), 0 2px 6px rgba(16, 24, 40, .06);
            overflow: hidden;
        }
        .login-box .box-header {
            border: none !important;
            padding: 34px 38px 2px;
            text-align: center;
        }
        .login-box .box-title { font-size: 24px; font-weight: 600; color: #1a2433; margin: 0; }
        .login-box .login-subtitle {
            color: #6b7890; font-size: 12.5px; margin: 7px 0 0;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .login-box .login-box-body { padding: 22px 38px 0; background: transparent; }
        .login-box .box-footer { border: none !important; padding: 6px 38px 34px; background: transparent; }

        .login-box .control-label { font-weight: 600; color: #36425a; font-size: 13px; margin-bottom: 6px; }
        .login-box input.form-control {
            height: 46px; border-radius: 10px; border: 1px solid #d6dde7; box-shadow: none;
            font-size: 15px; padding: 10px 14px; transition: border-color .15s, box-shadow .15s;
        }
        .login-box input.form-control:focus { border-color: #2f6bd4; box-shadow: 0 0 0 3px rgba(47, 107, 212, .16); }
        .login-box .input-group-addon {
            border-radius: 0 10px 10px 0; border: 1px solid #d6dde7; background: #f4f6f9; color: #6b7890;
        }
        .login-box label.form-control {
            border: none !important; box-shadow: none; height: auto; padding: 2px 0;
            font-weight: 400; color: #5a6678; cursor: pointer;
        }

        .login-box .btn-primary {
            height: 48px; border-radius: 10px; font-weight: 600; font-size: 15px;
            background: #2f6bd4; border-color: #2f6bd4; box-shadow: 0 2px 8px rgba(47, 107, 212, .28);
            transition: background .15s, transform .04s;
        }
        .login-box .btn-primary:hover, .login-box .btn-primary:focus { background: #2559b8; border-color: #2559b8; }
        .login-box .btn-primary:active { transform: translateY(1px); }

        .login-box .btn-sso {
            display: flex; align-items: center; justify-content: center; gap: 10px; height: 54px; font-size: 16px;
        }
        .login-box .btn-sso i { font-size: 18px; }
        .login-box .sso-help { text-align: center; color: #6b7890; font-size: 13px; margin: 15px 0 0; }
        .login-box .sso-need-access { text-align: center; margin: 5px 0 0; color: #8a94a6; font-size: 12.5px; }
        .login-box .sso-need-access a { color: #2f6bd4; }
        .login-box .sso-alt {
            text-align: center; margin-top: 18px; padding-top: 15px; border-top: 1px solid #eef1f5;
        }
        .login-box .sso-alt a { color: #2f6bd4; font-size: 13px; }

        /* AdminLTE forces solid callout colours with !important, so override in kind. */
        .login-box .alert { border-radius: 10px; font-size: 14px; padding: 13px 15px; text-align: left; }
        .login-box .alert-warning {
            background: #fff5e2 !important; color: #8a5a00 !important; border: 1px solid #f6dcaa !important;
        }
        .login-box .alert-danger {
            background: #fdecea !important; color: #a4291c !important; border: 1px solid #f5c6c2 !important;
        }
        .login-box .alert i, .login-box .alert .close { color: inherit !important; opacity: .85; }
        .login-box .alert .close { opacity: .45; text-shadow: none; }
    </style>

    <form role="form" action="{{ url('/login') }}" method="POST" autocomplete="{{ (config('auth.login_autocomplete') === true) ? 'on' : 'off'  }}">
        <input type="hidden" name="_token" value="{{ csrf_token() }}" />
        @if (request()->has('nosaml'))
            {{-- Preserve the super-admin bypass through the POST so local login is allowed. --}}
            <input type="hidden" name="nosaml" value="1">
        @endif


        <!-- this is a hack to prevent Chrome from trying to autocomplete fields -->
        <input type="text" name="prevent_autofill" id="prevent_autofill" value="" style="display:none;" aria-hidden="true">
        <input type="password" name="password_fake" id="password_fake" value="" style="display:none;" aria-hidden="true">

        <div class="container">
            <div class="row">

                <div class="col-md-4 col-md-offset-4">

                    @if (($snipeSettings->google_login=='1') && ($snipeSettings->google_client_id!='') && ($snipeSettings->google_client_secret!=''))

                        <br><br>
                        <a href="{{ route('google.redirect')  }}" class="btn btn-block btn-social btn-google btn-lg">
                            <i class="fa-brands fa-google"></i>
                            {{ trans('auth/general.google_login') }}
                        </a>

                        <div class="separator">{{ strtoupper(trans('general.or')) }}</div>
                    @endif


                    <div class="box login-box">
                        <div class="box-header">
                            <h1 class="box-title">{{ trans('auth/general.login_prompt')  }}</h1>
                            <p class="login-subtitle">{{ trans('auth/general.login_subtitle')  }}</p>
                        </div>


                        <div class="login-box-body">
                            <div class="row">

                                @if ($snipeSettings->login_note)
                                    <div class="col-md-12">
                                        <div class="alert alert-info">
                                            {!!  Helper::parseEscapedMarkedown($snipeSettings->login_note)  !!}
                                        </div>
                                    </div>
                                @endif

                                <!-- Notifications -->
                                @include('notifications')

                                @if ($showLocalLogin)
                                <div class="col-md-12">
                                    <!-- CSRF Token -->


                                    <fieldset name="login" aria-label="login">

                                        <div class="form-group{{ $errors->has('username') ? ' has-error' : '' }}">
                                            <label for="username" class="control-label">
                                                <x-icon type="user" />
                                                {{ trans('admin/users/table.username')  }}
                                            </label>
                                            <input class="form-control" placeholder="{{ trans('admin/users/table.username')  }}" name="username" type="text" id="username" autocomplete="{{ (config('auth.login_autocomplete') === true) ? 'on' : 'off'  }}" autocapitalize="off" spellcheck="false" autofocus>
                                            {!! $errors->first('username', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                        </div>


                                        <div class="form-group{{ $errors->has('password') ? ' has-error' : '' }}">
                                            <label for="password" class="control-label">
                                                <x-icon type="password" />
                                                {{ trans('admin/users/table.password')  }}
                                            </label>

                                            <div class="input-group">
                                                <input class="form-control" placeholder="{{ trans('admin/users/table.password')  }}" name="password" type="password" id="password-field" autocomplete="{{ (config('auth.login_autocomplete') === true) ? 'on' : 'off'  }}" autocorrect="off" autocapitalize="off" spellcheck="false">
                                                <span class="input-group-addon">
                                                   <i data-toggle="#password-field" class="fa fa-fw fa-eye toggle-password" aria-hidden="true"></i>
                                                    <span class="sr-only">Toggle password visibility</span>
                                                </span>
                                            </div>

                                            {!! $errors->first('password', '<span class="alert-msg" aria-hidden="true"><i class="fas fa-times" aria-hidden="true"></i> :message</span>') !!}
                                        </div>

                                        <div class="form-group">
                                            <label class="form-control">
                                                <input name="remember" type="checkbox" value="1" id="remember"> {{ trans('auth/general.remember_me')  }}
                                            </label>
                                        </div>
                                    </fieldset>
                                </div> <!-- end col-md-12 -->
                                @endif
                            </div> <!-- end row -->

                            @if ($showLocalLogin && $snipeSettings->saml_enabled)
                            <div class="row">
                                <div class="text-right col-md-12">
                                    <a href="{{ route('saml.login')  }}">{{ trans('auth/general.saml_login')  }}</a>
                                </div>
                            </div>
                            @endif
                        </div>
                        <div class="box-footer">
                            @if (!$showLocalLogin)
                                <a class="btn btn-primary btn-block btn-sso" href="{{ route('saml.login')  }}">
                                    <i class="fa-brands fa-microsoft" aria-hidden="true"></i> {{ trans('auth/general.sso_signin')  }}
                                </a>
                                <p class="sso-help">{{ trans('auth/general.sso_help')  }}</p>
                                <p class="sso-need-access">{{ trans('auth/general.sso_need_access')  }}</p>
                            @else
                                <button class="btn btn-primary btn-block" type="submit" id="submit">
                                    {{ trans('auth/general.login')  }}
                                </button>
                            @endif

                            @if ($snipeSettings->custom_forgot_pass_url)
                                <div class="col-md-12 text-center" style="padding-top: 15px;">
                                    <a href="{{ $snipeSettings->custom_forgot_pass_url  }}" rel="noopener">{{ trans('auth/general.forgot_password')  }}</a>
                                </div>
                            @elseif ($showLocalLogin)
                                <div class="col-md-12 text-center" style="padding-top: 15px;">
                                    <a href="{{ route('password.request')  }}">{{ trans('auth/general.forgot_password')  }}</a>
                                </div>
                            @endif

                        </div>

                    </div> <!-- end login box -->


                </div> <!-- col-md-4 -->

            </div> <!-- end row -->
        </div> <!-- end container -->
    </form>

@stop