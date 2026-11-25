import MarcaFeder from '../../components/ui/MarcaFeder'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import CampoTexto from '../../components/ui/CampoTexto'
import { useFormulario } from '../../hooks/useFormulario'
import { useSesion } from '../../hooks/useSesion'
import { correo, requerido } from '../../utils/validaciones'
import './Autenticacion.css'

const REGLAS = {
  correo: [requerido('Ingresa tu correo electrónico.'), correo()],
  password: [requerido('Ingresa tu contraseña.')],
}

export default function LoginPagina() {
  const { iniciarSesion } = useSesion()
  const navegar = useNavigate()
  const ubicacion = useLocation()
  const destino = ubicacion.state?.desde || '/'
  const { valores, errores, errorGeneral, enviando, cambiar, validarCampo, enviar } =
    useFormulario({ correo: '', password: '' }, REGLAS)

  const alEnviar = enviar(async (datos) => {
    await iniciarSesion({ correo: datos.correo.trim(), password: datos.password })
    navegar(destino, { replace: true })
  })

  return (
    <div className="autenticacion">
      <section className="autenticacion__tarjeta" aria-labelledby="titulo-login">
        <MarcaFeder />
        <h1 id="titulo-login">Iniciar sesión</h1>
        <p className="autenticacion__bajada">Ingresa con tu correo y contraseña de FEDER.</p>

        {ubicacion.state?.desde && (
          <Alerta tipo="info">Necesitas iniciar sesión para continuar.</Alerta>
        )}
        {errorGeneral && <Alerta tipo="error">{errorGeneral}</Alerta>}

        <form className="autenticacion__formulario" onSubmit={alEnviar} noValidate>
          <CampoTexto etiqueta="Correo electrónico" nombre="correo" tipo="email" autoComplete="email" obligatorio
            value={valores.correo} onChange={cambiar} onBlur={validarCampo} error={errores.correo} />
          <CampoTexto etiqueta="Contraseña" nombre="password" tipo="password" autoComplete="current-password" obligatorio
            value={valores.password} onChange={cambiar} onBlur={validarCampo} error={errores.password} />
          <Boton tipo="submit" bloque cargando={enviando}>Ingresar</Boton>
        </form>

        <p className="autenticacion__pie">
          ¿Todavía no tienes cuenta? <Link to="/registro">Regístrate como cliente</Link>
        </p>
      </section>
    </div>
  )
}
