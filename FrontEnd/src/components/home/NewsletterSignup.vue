<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { sendNewsletterEmail } from '@/services/newsletterService'

const { t, tm } = useI18n()

const newsletter = computed(() => tm('home.newsletter') ?? {})
const newsletterForm = computed(() => tm('home.newsletter.form') ?? {})
const finePrint = computed(() => t('home.newsletter.finePrint'))

const email = ref('')
const name = ref('')
const status = ref('idle')
const message = ref('')

const isLoading = computed(() => status.value === 'loading')
const isSuccess = computed(() => status.value === 'success')

async function handleSubmit(event) {
  event.preventDefault()
  if (!email.value) {
    status.value = 'error'
    message.value = 'Please provide an email address.'
    return
  }
  status.value = 'loading'
  message.value = ''
  try {
    await sendNewsletterEmail({ email: email.value, name: name.value })
    status.value = 'success'
    message.value = 'We just sent a welcome email to your inbox.'
    email.value = ''
    name.value = ''
  } catch (error) {
    status.value = 'error'
    message.value = error?.message || 'Unable to send the email right now.'
  }
}
</script>

<template>
  <section class="earthletter" id="newsletter">
    <div class="earthletter-card">
      <div class="earthletter-copy">
        <p class="eyebrow">Newsletter</p>
        <h2>{{ newsletter.title }}</h2>
        <p>{{ newsletter.subtitle }}</p>
      </div>
      <form class="earthletter-form" @submit="handleSubmit" novalidate>
        <label class="sr-only" for="newsletter-name">Name</label>
        <input
          id="newsletter-name"
          v-model="name"
          type="text"
          inputmode="text"
          autocomplete="name"
          placeholder="Preferred name (optional)"
        />
        <label class="sr-only" for="newsletter-email">{{ newsletterForm.label }}</label>
        <input
          id="newsletter-email"
          v-model="email"
          type="email"
          inputmode="email"
          autocomplete="email"
          :placeholder="newsletterForm.placeholder"
          required
        />
        <button type="submit" class="btn primary" :disabled="isLoading">
          <span v-if="isLoading">Sending...</span>
          <span v-else>{{ newsletterForm.cta }}</span>
        </button>
      </form>
      <p class="fine-print">{{ finePrint }}</p>
      <p v-if="message" class="feedback" :class="{ success: isSuccess, error: status === 'error' }">
        {{ message }}
      </p>
    </div>
  </section>
</template>

<style scoped>
.earthletter {
  padding: 0 clamp(0.5rem, 4vw, 2rem);
}

.earthletter-card {
  border-radius: 32px;
  background: linear-gradient(135deg, #e6fff1, #ffffff);
  border: 1px solid #daf0e2;
  padding: clamp(1.5rem, 4vw, 3rem);
  text-align: center;
  box-shadow: 0 20px 40px rgba(12, 66, 41, 0.12);
}

.earthletter-copy h2 {
  margin: 0;
  font-size: clamp(2rem, 3vw, 2.6rem);
  color: #083421;
}

.earthletter-copy p {
  margin: 0.75rem 0 1.5rem;
  color: #456152;
}

.eyebrow {
  margin: 0 0 0.5rem;
  text-transform: uppercase;
  letter-spacing: 0.3em;
  font-size: 0.75rem;
  color: #6b8477;
}

.earthletter-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: center;
  margin-bottom: 1rem;
}

.earthletter-form input {
  min-width: clamp(220px, 30vw, 340px);
  padding: 0.95rem 1.1rem;
  border-radius: 999px;
  border: 1px solid #cfe5d7;
  font-size: 1rem;
}

.earthletter-form input:focus {
  outline: none;
  border-color: #2c7a55;
  box-shadow: 0 0 0 3px rgba(44, 122, 85, 0.25);
}

.fine-print {
  margin: 0;
  font-size: 0.85rem;
  color: #577462;
}

.feedback {
  margin-top: 0.75rem;
  font-size: 0.9rem;
}

.feedback.success {
  color: #1b5e39;
}

.feedback.error {
  color: #b2333c;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

@media (max-width: 720px) {
  .earthletter-form {
    flex-direction: column;
  }
}
</style>
