package com.animeh.app.ui.screens.auth

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.R
import com.animeh.app.core.AppError
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
    var login by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    LaunchedEffect(state.done) { if (state.done) onSuccess() }

    AuthScaffold(title = stringResource(R.string.auth_login), onBack = onBack) {
        OutlinedTextField(
            value = login,
            onValueChange = { login = it; viewModel.clearError() },
            label = { Text(stringResource(R.string.auth_login_field)) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Email,
                imeAction = ImeAction.Next,
            ),
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(12.dp))

        PasswordField(
            value = password,
            onValueChange = { password = it; viewModel.clearError() },
            label = stringResource(R.string.auth_password),
            imeAction = ImeAction.Done,
            onDone = { viewModel.login(login, password) },
        )

        ErrorText(state.error?.let { error ->
            // A server-written message is the useful one; otherwise the app's
            // own string for that class of failure.
            if (error is AppError.Message) error.text else stringResource(error.messageRes)
        })

        Spacer(Modifier.height(20.dp))

        Button(
            onClick = { viewModel.login(login, password) },
            enabled = !state.submitting,
            modifier = Modifier.fillMaxWidth().height(50.dp),
        ) {
            if (state.submitting) {
                CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Text(stringResource(R.string.auth_login))
            }
        }

        TextButton(onClick = onForgotPassword, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.auth_forgot))
        }

        TextButton(onClick = onRegister, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.auth_no_account))
        }
    }
}

@Composable
fun RegisterScreen(
    onSuccess: () -> Unit,
    onBack: () -> Unit,
    viewModel: AuthViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    var username by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    LaunchedEffect(state.done) { if (state.done) onSuccess() }

    AuthScaffold(title = stringResource(R.string.auth_register), onBack = onBack) {
        OutlinedTextField(
            value = username,
            onValueChange = { username = it; viewModel.clearError() },
            label = { Text(stringResource(R.string.auth_username)) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(12.dp))

        OutlinedTextField(
            value = email,
            onValueChange = { email = it; viewModel.clearError() },
            label = { Text(stringResource(R.string.auth_email)) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(12.dp))

        PasswordField(
            value = password,
            onValueChange = { password = it; viewModel.clearError() },
            label = stringResource(R.string.auth_password),
            imeAction = ImeAction.Done,
            onDone = { viewModel.register(username, email, password) },
            // Stated up front rather than as an error after submitting.
            supporting = stringResource(R.string.auth_password_too_short),
        )

        ErrorText(state.error?.let { error ->
            if (error is AppError.Message) error.text else stringResource(error.messageRes)
        })

        Spacer(Modifier.height(20.dp))

        Button(
            onClick = { viewModel.register(username, email, password) },
            enabled = !state.submitting,
            modifier = Modifier.fillMaxWidth().height(50.dp),
        ) {
            if (state.submitting) {
                CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Text(stringResource(R.string.auth_register))
            }
        }

        TextButton(onClick = onBack, modifier = Modifier.fillMaxWidth()) {
            Text(stringResource(R.string.auth_have_account))
        }
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
