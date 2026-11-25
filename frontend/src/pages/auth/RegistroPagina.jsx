import MarcaFeder from '../../components/ui/MarcaFeder'
import { Link, useNavigate } from 'react-router-dom'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import CampoTexto from '../../components/ui/CampoTexto'
import Casilla from '../../components/ui/Casilla'
import { useFormulario } from '../../hooks/useFormulario'
import { useSesion } from '../../hooks/useSesion'
import {
  aceptado, contrasenaSegura, correo, enteroEntre, igualA, longitudMaxima, patron, requerido, soloLetras, telefonoBoliviano,
} from '../../utils/validaciones'
import './Autenticacion.css'

const VALORES_INICIALES = {
  nombres: '', apellidos: '', correo: '', ci: '', ci_complemento: '', telefono: '',
  password: '', password_confirmation: '', acepta_terminos: false,
}

// Mismas reglas que valida el servidor (RegistroRequest).
const REGLAS = {
  nombres: [requerido('Ingresa tus nombres.'), soloLetras('Los nombres'), longitudMaxima(80, 'El campo nombres')],
  apellidos: [requerido('Ingresa tus apellidos.'), soloLetras('Los apellidos'), longitudMaxima(80, 'El campo apellidos')],
  correo: [requerido('Ingresa tu correo electrónico.'), correo(), longitudMaxima(120, 'El correo')],
  ci: [requerido('Ingresa tu carnet de identidad.'),
    enteroEntre(10000, 999999999, 'El carnet de identidad debe tener entre 5 y 9 dígitos.')],
  ci_complemento: [patron(/^[0-9A-Za-z]{2}$/, 'El complemento son 2 caracteres: números o letras (por ejemplo 1A).')],
  telefono: [requerido('Ingresa tu teléfono.'), telefonoBoliviano()],
  password: [requerido('Crea una contraseña.'), contrasenaSegura()],
  password_confirmation: [requerido('Repite la contraseña.'), igualA('password', 'Las contraseñas no coinciden.')],
  acepta_terminos: [aceptado('Debes aceptar los términos y condiciones para registrarte.')],
}

export default function RegistroPagina() {
  const { registrarse } = useSesion()
  const navegar = useNavigate()
  const { valores, errores, errorGeneral, enviando, cambiar, validarCampo, enviar } =
    useFormulario(VALORES_INICIALES, REGLAS)

  const alEnviar = enviar(async (datos) => {
    await registrarse({ ...datos, ci_complemento: datos.ci_complemento.trim().toUpperCase() || null })
    navegar('/', { replace: true })
  })

  const propiedades = (nombre) => ({
    nombre, value: valores[nombre], onChange: cambiar, onBlur: validarCampo, error: errores[nombre],
  })

  return (
    <div className="autenticacion">
      <section className="autenticacion__tarjeta autenticacion__tarjeta--ancha" aria-labelledby="titulo-registro">
        <MarcaFeder />
        <h1 id="titulo-registro">Crear cuenta de cliente</h1>
        <p className="autenticacion__bajada">
          Regístrate para comprar en FEDER. Tu cuenta queda lista al instante.
        </p>

        {errorGeneral && <Alerta tipo="error">{errorGeneral}</Alerta>}

        <form className="autenticacion__formulario autenticacion__formulario--rejilla" onSubmit={alEnviar} noValidate>
          <CampoTexto etiqueta="Nombres" autoComplete="given-name" obligatorio {...propiedades('nombres')} />
          <CampoTexto etiqueta="Apellidos" autoComplete="family-name" obligatorio {...propiedades('apellidos')} />
          <CampoTexto etiqueta="Correo electrónico" tipo="email" autoComplete="email" obligatorio
            {...propiedades('correo')} />
          <CampoTexto etiqueta="Teléfono" tipo="tel" inputMode="numeric" autoComplete="tel" obligatorio
            ayuda="8 dígitos, sin código de país." {...propiedades('telefono')} />
          <CampoTexto etiqueta="Carnet de identidad" inputMode="numeric" obligatorio {...propiedades('ci')} />
          <CampoTexto etiqueta="Complemento" maxLength={2} ayuda="Opcional. Solo si tu carnet lo tiene."
            {...propiedades('ci_complemento')} />
          <CampoTexto etiqueta="Contraseña" tipo="password" autoComplete="new-password" obligatorio
            ayuda="Mínimo 8 caracteres, con letras y números." {...propiedades('password')} />
          <CampoTexto etiqueta="Repite la contraseña" tipo="password" autoComplete="new-password" obligatorio
            {...propiedades('password_confirmation')} />

          <div className="autenticacion__ancho-completo">
            <Casilla nombre="acepta_terminos" checked={valores.acepta_terminos} onChange={cambiar}
              error={errores.acepta_terminos}>
              Acepto los términos y condiciones de uso de FEDER y el tratamiento de mis datos para gestionar mis compras.
            </Casilla>
          </div>

          <div className="autenticacion__ancho-completo">
            <Boton tipo="submit" bloque cargando={enviando}>Crear mi cuenta</Boton>
          </div>
        </form>

        <p className="autenticacion__pie">
          ¿Ya tienes cuenta? <Link to="/ingresar">Inicia sesión</Link>
        </p>
      </section>
    </div>
  )
}
