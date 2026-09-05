package com.animeh.app.ui.screens.auth

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material.icons.outlined.Email
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.R
import com.animeh.app.core.AppError
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.TextSecondary

@Composable
fun LoginScreen(
    onSuccess: () -> Unit,
    onRegister: () -> Unit,
    onForgotPassword: () -> Unit,
    onBack: () -> Unit,
    viewModel: AuthViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val remembered by viewModel.rememberedLogin.collectAsStateWithLifecycle()

    var login by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var rememberMe by rememberSaveable { mutableStateOf(false) }

    // Filled once, from what was stored, and only while the field is still
    // untouched — typing over a remembered name must not be undone by the
    // preference arriving a frame later.
    var prefilled by rememberSaveable { mutableStateOf(false) }

    LaunchedEffect(remembered) {
        if (!prefilled && remembered.isNotBlank()) {
            login = remembered
            rememberMe = true
            prefilled = true
        }
    }

    LaunchedEffect(state.done) { if (state.done) onSuccess() }

    val submit = { viewModel.login(login, password, rememberMe) }

    AuthLayout(
        cover = {
            AuthCover(
                asset = "login",
                lead = stringResource(R.string.auth_login_title_lead),
                accent = stringResource(R.string.auth_login_title_accent),
                subtitleLead = stringResource(R.string.auth_login_subtitle_lead),
                subtitleAccent = stringResource(R.string.auth_login_subtitle_accent),
                heightFraction = LOGIN_COVER_FRACTION,
                onBack = onBack,
            )
        },
    ) {
        AuthCard {
            AuthField(
                value = login,
                onValueChange = { login = it; viewModel.clearError() },
                placeholder = stringResource(R.string.auth_login_field),
                icon = Icons.Outlined.Person,
                keyboardType = KeyboardType.Email,
            )

            Spacer(Modifier.height(12.dp))

            AuthPasswordField(
                value = password,
                onValueChange = { password = it; viewModel.clearError() },
                placeholder = stringResource(R.string.auth_password),
                icon = Icons.Outlined.Lock,
                imeAction = ImeAction.Done,
                onDone = submit,
            )

            Spacer(Modifier.height(6.dp))

            Row(
                Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier.clickable { rememberMe = !rememberMe },
                ) {
                    Checkbox(
                        checked = rememberMe,
                        onCheckedChange = { rememberMe = it },
                        colors = CheckboxDefaults.colors(checkedColor = AccentPrimary),
                    )
                    Text(
                        stringResource(R.string.auth_remember_me),
                        style = MaterialTheme.typography.bodyMedium,
                        color = TextSecondary,
                    )
                }

                Spacer(Modifier.weight(1f))

                TextButton(onClick = onForgotPassword) {
                    Text(
                        stringResource(R.string.auth_forgot_question),
                        style = MaterialTheme.typography.bodyMedium,
                        color = AccentBright,
                    )
                }
            }

            ErrorText(state.error?.let { error ->
                // A server-written message is the useful one; otherwise the
                // app's own string for that class of failure.
                if (error is AppError.Message) error.text else stringResource(error.messageRes)
            })

            Spacer(Modifier.height(14.dp))

            AuthSubmitButton(
                text = stringResource(R.string.auth_login),
                onClick = submit,
                enabled = login.isNotBlank() && password.isNotBlank(),
                loading = state.submitting,
            )
        }

        Spacer(Modifier.height(20.dp))

        AuthSwitch(
            question = stringResource(R.string.auth_no_account_question),
            action = stringResource(R.string.auth_register),
            onClick = onRegister,
        )
    }
}

@Composable
fun RegisterScreen(
    onSuccess: () -> Unit,
    onBack: () -> Unit,
    viewModel: AuthViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    var username by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var repeat by rememberSaveable { mutableStateOf("") }
    var accepted by rememberSaveable { mutableStateOf(false) }

    LaunchedEffect(state.done) { if (state.done) onSuccess() }

    // Checked here rather than only after submitting: the server never sees
    // the second field, so nothing else can catch this.
    val mismatch = repeat.isNotEmpty() && repeat != password

    val submit = { viewModel.register(username, email, password) }

    AuthLayout(
        cover = {
            AuthCover(
                asset = "register",
                lead = stringResource(R.string.auth_register_title_lead),
                accent = stringResource(R.string.auth_register_title_accent),
                subtitleLead = stringResource(R.string.auth_register_subtitle),
                heightFraction = REGISTER_COVER_FRACTION,
                onBack = onBack,
            )
        },
    ) {
        AuthCard {
            AuthField(
                value = username,
                onValueChange = { username = it; viewModel.clearError() },
                placeholder = stringResource(R.string.auth_username),
                icon = Icons.Outlined.Person,
            )

            Spacer(Modifier.height(12.dp))

            AuthField(
                value = email,
                onValueChange = { email = it; viewModel.clearError() },
                placeholder = stringResource(R.string.auth_email),
                icon = Icons.Outlined.Email,
                keyboardType = KeyboardType.Email,
            )

            Spacer(Modifier.height(12.dp))

            AuthPasswordField(
                value = password,
                onValueChange = { password = it; viewModel.clearError() },
                placeholder = stringResource(R.string.auth_password),
                icon = Icons.Outlined.Lock,
            )

            Spacer(Modifier.height(10.dp))

            PasswordStrength(password)

            Spacer(Modifier.height(12.dp))

            AuthPasswordField(
                value = repeat,
                onValueChange = { repeat = it; viewModel.clearError() },
                placeholder = stringResource(R.string.auth_password_repeat),
                icon = Icons.Outlined.Lock,
                imeAction = ImeAction.Done,
                onDone = submit,
            )

            if (mismatch) {
                ErrorText(stringResource(R.string.auth_password_mismatch))
            }

            Spacer(Modifier.height(10.dp))

            TermsCheckbox(checked = accepted, onCheckedChange = { accepted = it })

            ErrorText(state.error?.let { error ->
                if (error is AppError.Message) error.text else stringResource(error.messageRes)
            })

            Spacer(Modifier.height(14.dp))

            AuthSubmitButton(
                text = stringResource(R.string.auth_register),
                onClick = submit,
                // Every rule the form can check itself, checked before the
                // button is offered: a disabled button with a visible reason
                // beats a request that comes back refused.
                enabled = username.isNotBlank() &&
                    email.isNotBlank() &&
                    password.length >= MIN_PASSWORD &&
                    repeat == password &&
                    accepted,
                loading = state.submitting,
            )
        }

        Spacer(Modifier.height(20.dp))

        AuthSwitch(
            question = stringResource(R.string.auth_have_account_question),
            action = stringResource(R.string.auth_login),
            onClick = onBack,
        )
    }
}

/**
 * Artwork at the top, everything else scrolling under it.
 *
 * One scroll for the whole page rather than a fixed cover with a scrolling
 * card: with the keyboard up on a short phone the card has to be able to move
 * over the artwork, and pinning the image is what makes a form unreachable.
 */
@Composable
private fun AuthLayout(
    cover: @Composable () -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(rememberScrollState())
            .imePadding(),
    ) {
        cover()

        Column(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 20.dp)
                .padding(top = 18.dp, bottom = 32.dp)
                .navigationBarsPadding(),
            content = content,
        )
    }
}

/**
 * The box that has to be ticked, and what it says.
 *
 * The whole row is the target rather than just the box: a 20dp square is a
 * miss on a phone, and the sentence is what people aim at anyway.
 */
@Composable
private fun TermsCheckbox(checked: Boolean, onCheckedChange: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable { onCheckedChange(!checked) },
        verticalAlignment = Alignment.Top,
    ) {
        Checkbox(
            checked = checked,
            onCheckedChange = onCheckedChange,
            colors = CheckboxDefaults.colors(checkedColor = AccentPrimary),
        )

        Spacer(Modifier.width(4.dp))

        Text(
            buildAnnotatedString {
                withStyle(SpanStyle(color = AccentBright)) {
                    append(stringResource(R.string.auth_terms_use))
                }
                append(stringResource(R.string.auth_terms_and))
                withStyle(SpanStyle(color = AccentBright)) {
                    append(stringResource(R.string.auth_terms_privacy))
                }
                append(stringResource(R.string.auth_terms_tail))
            },
            style = MaterialTheme.typography.bodySmall,
            color = TextSecondary,
            modifier = Modifier.padding(top = 14.dp),
        )
    }
}

@Composable
fun ForgotPasswordScreen(
    onBack: () -> Unit,
    viewModel: AuthViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    var login by remember { mutableStateOf("") }
    val sentMessage = stringResource(R.string.auth_reset_sent)

    AuthScaffold(title = stringResource(R.string.auth_forgot), onBack = onBack) {
        OutlinedTextField(
            value = login,
            onValueChange = { login = it },
            label = { Text(stringResource(R.string.auth_login_field)) },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )

        state.message?.let {
            Spacer(Modifier.height(12.dp))
            Text(it, style = MaterialTheme.typography.bodyMedium, color = TextSecondary)
        }

        Spacer(Modifier.height(20.dp))

        Button(
            onClick = { viewModel.forgotPassword(login, sentMessage) },
            enabled = !state.submitting,
            modifier = Modifier.fillMaxWidth().height(50.dp),
        ) {
            Text(stringResource(R.string.auth_forgot))
        }
    }
}

@Composable
fun ChangePasswordScreen(
    onBack: () -> Unit,
    viewModel: AuthViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    var current by remember { mutableStateOf("") }
    var next by remember { mutableStateOf("") }

    LaunchedEffect(state.done) { if (state.done) onBack() }

    AuthScaffold(title = stringResource(R.string.profile_change_password), onBack = onBack) {
        PasswordField(
            value = current,
            onValueChange = { current = it; viewModel.clearError() },
            label = stringResource(R.string.auth_password_current),
            imeAction = ImeAction.Next,
        )

        Spacer(Modifier.height(12.dp))

        PasswordField(
            value = next,
            onValueChange = { next = it; viewModel.clearError() },
            label = stringResource(R.string.auth_password_new),
            imeAction = ImeAction.Done,
            onDone = { viewModel.changePassword(current, next) },
        )

        ErrorText(state.error?.let { error ->
            if (error is AppError.Message) error.text else stringResource(error.messageRes)
        })

        Spacer(Modifier.height(20.dp))

        Button(
            onClick = { viewModel.changePassword(current, next) },
            enabled = !state.submitting,
            modifier = Modifier.fillMaxWidth().height(50.dp),
        ) {
            Text(stringResource(R.string.save))
        }
    }
}

@Composable
private fun AuthScaffold(
    title: String,
    onBack: () -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        Modifier
            .fillMaxSize()
            .statusBarsPadding()
            .verticalScroll(rememberScrollState())
            .padding(24.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back))
            }
            Spacer(Modifier.width(8.dp))
            Text(title, style = MaterialTheme.typography.headlineMedium)
        }

        Spacer(Modifier.height(28.dp))

        content()
    }
}

@Composable
private fun PasswordField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    imeAction: ImeAction,
    onDone: (() -> Unit)? = null,
    supporting: String? = null,
) {
    var visible by remember { mutableStateOf(false) }

    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        singleLine = true,
        visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = imeAction),
        keyboardActions = androidx.compose.foundation.text.KeyboardActions(
            onDone = { onDone?.invoke() },
        ),
        trailingIcon = {
            IconButton(onClick = { visible = !visible }) {
                Icon(
                    if (visible) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
                    contentDescription = null,
                )
            }
        },
        supportingText = supporting?.let { { Text(it) } },
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun ErrorText(message: String?) {
    if (message == null) return

    Spacer(Modifier.height(12.dp))
    Text(
        text = message,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.error,
    )
}
