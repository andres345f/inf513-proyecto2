import { adminApi } from '../../api/adminApi'
import Alerta from '../ui/Alerta'
import Boton from '../ui/Boton'
import CampoTexto from '../ui/CampoTexto'
import { useFormulario } from '../../hooks/useFormulario'
import { nombreRol } from '../../utils/formato'
import {
  contrasenaSegura, correo, enteroEntre, igualA, longitudMaxima, patron, requerido, soloLetras, telefonoBoliviano,
} from '../../utils/validaciones'
import './FormularioNuevoUsuario.css'

const ROLES = ['PERSONAL_FEDER', 'CLIENTE', 'PRODUCTOR', 'ADMINISTRADOR']

const INICIALES = {
  nombres: '', apellidos: '', correo: '', ci: '', ci_complemento: '', telefono: '', password: '', password_confirmation: '', rol: 'PERSONAL_FEDER',
}

const REGLAS = {
  nombres: [requerido('Ingresa los nombres.'), soloLetras('Los nombres'), longitudMaxima(80, 'El campo nombres')],
  apellidos: [requerido('Ingresa los apellidos.'), soloLetras('Los apellidos'), longitudMaxima(80, 'El campo apellidos')],
  correo: [requerido('Ingresa el correo electrónico.'), correo()],
  ci: [requerido('Ingresa el carnet de identidad.'), enteroEntre(10000, 999999999, 'El carnet debe tener entre 5 y 9 dígitos.')],
  ci_complemento: [patron(/^[0-9A-Za-z]{2}$/, 'El complemento son 2 caracteres.')],
  telefono: [requerido('Ingresa el teléfono.'), telefonoBoliviano()],
  password: [requerido('Define una contraseña inicial.'), contrasenaSegura()],
  password_confirmation: [requerido('Repite la contraseña.'), igualA('password', 'Las contraseñas no coinciden.')],
}

/** Alta de cuenta por el administrador, con rol inicial. */
export default function FormularioNuevoUsuario({ alCrear, alCancelar }) {
  const { valores, errores, errorGeneral, enviando, cambiar, validarCampo, enviar } = useFormulario(INICIALES, REGLAS)
  const p = (nombre) => ({ nombre, value: valores[nombre], onChange: cambiar, onBlur: validarCampo, error: errores[nombre] })

  const alEnviar = enviar(async (datos) => {
    await adminApi.crearUsuario({ ...datos, ci_complemento: datos.ci_complemento.trim().toUpperCase() || null })
    alCrear()
  })

  return (
    <form className="nuevo-usuario" onSubmit={alEnviar} noValidate aria-labelledby="titulo-nuevo-usuario">
      <h2 id="titulo-nuevo-usuario">Nueva cuenta</h2>
      {errorGeneral && <Alerta tipo="error">{errorGeneral}</Alerta>}
      <div className="nuevo-usuario__rejilla">
        <CampoTexto etiqueta="Nombres" obligatorio {...p('nombres')} />
        <CampoTexto etiqueta="Apellidos" obligatorio {...p('apellidos')} />
        <CampoTexto etiqueta="Correo electrónico" tipo="email" obligatorio {...p('correo')} />
        <CampoTexto etiqueta="Teléfono" inputMode="numeric" obligatorio {...p('telefono')} />
        <CampoTexto etiqueta="Carnet de identidad" inputMode="numeric" obligatorio {...p('ci')} />
        <CampoTexto etiqueta="Complemento" maxLength={2} {...p('ci_complemento')} />
        <CampoTexto etiqueta="Contraseña inicial" tipo="password" autoComplete="new-password" obligatorio {...p('password')} />
        <CampoTexto etiqueta="Repite la contraseña" tipo="password" autoComplete="new-password" obligatorio {...p('password_confirmation')} />
        <div className="nuevo-usuario__rol">
          <label htmlFor="nuevo-rol">Rol inicial</label>
          <select id="nuevo-rol" name="rol" value={valores.rol} onChange={cambiar}>
            {ROLES.map((r) => <option key={r} value={r}>{nombreRol(r)}</option>)}
          </select>
        </div>
      </div>
      <div className="nuevo-usuario__acciones">
        <Boton variante="fantasma" onClick={alCancelar}>Cancelar</Boton>
        <Boton tipo="submit" cargando={enviando}>Crear cuenta</Boton>
      </div>
    </form>
  )
}
